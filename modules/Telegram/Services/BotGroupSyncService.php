<?php
declare(strict_types=1);

namespace Modules\Telegram\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\GroupAdmins;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * 机器人所在群同步服务
 *
 * 背景（Bug）：后台新增机器人后，它在 Telegram 里「早已加入」的群不会出现在群列表里。
 * 原因是 Bot API 没有「列出机器人所在群」的接口，系统原来只依赖 webhook 的
 * my_chat_member / new_chat_members 事件——这些事件只有在机器人**新**加入群时才会推送，
 * 先加群、后登记机器人（或 webhook 未设置）的情况永远不会触发。
 *
 * 本服务提供主动同步：以库里已知的 chat_id 作为候选，用 getChatMember 逐个确认归属。
 */
class BotGroupSyncService
{
    /**
     * 机器人仍算「在群内」的成员状态
     */
    private const ACTIVE_STATUSES = ['creator', 'administrator', 'member', 'restricted'];

    /**
     * 单次同步最多检查多少个群，避免把请求打爆
     */
    private const MAX_CANDIDATES = 500;

    private const LOG_FILE = 'bot_group_sync';

    public function __construct(
        protected readonly LogMessageService $logMessageService,
    ) {}

    /**
     * 同步单个机器人所在的群
     *
     * @return array{total: int, synced: int, removed: int, skipped: int, errors: array}
     */
    public function syncForBot(Bots $bot): array
    {
        $result = ['total' => 0, 'synced' => 0, 'removed' => 0, 'skipped' => 0, 'errors' => []];

        if (empty($bot->api_token)) {
            $result['errors'][] = 'api_token is empty';
            return $result;
        }

        try {
            $telegram = app(BotApiFactory::class)->forBot($bot);
            $botTelegramId = $telegram->getMe()->getId();
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                self::LOG_FILE,
                ['bot_id' => $bot->id, 'error' => $e->getMessage()],
                'getMe 失败，无法同步机器人所在群',
                'error'
            );

            $result['errors'][] = $e->getMessage();

            return $result;
        }

        $candidates = $this->candidateChatIds();
        $result['total'] = count($candidates);

        foreach ($candidates as $chatId) {
            try {
                $member = $telegram->getChatMember([
                    'chat_id' => $chatId,
                    'user_id' => $botTelegramId,
                ]);

                if ($this->isActiveMember($member)) {
                    $this->upsertGroup($telegram, $bot, $chatId);
                    $result['synced']++;

                    continue;
                }

                // 机器人已不在该群：清理属于它的绑定关系，避免后台还显示这个群
                if ($this->detachGroup($bot, $chatId)) {
                    $result['removed']++;
                } else {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $message = $e->getMessage();

                // 只有明确「机器人不是群成员 / 会话不存在」才删除，网络抖动不能误删
                if ($this->isNotMemberError($message)) {
                    if ($this->detachGroup($bot, $chatId)) {
                        $result['removed']++;
                    } else {
                        $result['skipped']++;
                    }
                } else {
                    $result['skipped']++;
                    $result['errors'][] = ['chat_id' => $chatId, 'error' => $message];
                }

                $this->logMessageService->createLaravelLog(
                    self::LOG_FILE,
                    ['bot_id' => $bot->id, 'chat_id' => $chatId, 'error' => $message],
                    'getChatMember 失败',
                    'warning'
                );
            }
        }

        // 清掉历史遗留的「bot_id 写成了 Telegram uid」的脏数据
        $this->purgeLegacyRows($bot, $botTelegramId);

        return $result;
    }

    /**
     * 判断机器人是否仍在群内
     *
     * 注意：SDK 的 BaseObject::getStatus() 返回的是响应里的 ok 标记（true/false），
     * 不是成员状态，绝不能用它来判断。必须读 status 字段。
     */
    private function isActiveMember($member): bool
    {
        if (is_array($member)) {
            $status = $member['status'] ?? '';
            $isMember = $member['is_member'] ?? true;
        } elseif ($member instanceof \ArrayAccess) {
            $status = $member['status'] ?? '';
            $isMember = $member['is_member'] ?? true;
        } else {
            $status = $member->status ?? '';
            $isMember = $member->is_member ?? true;
        }

        $status = (string) $status;

        if (! in_array($status, self::ACTIVE_STATUSES, true)) {
            return false;
        }

        // restricted 状态下 is_member=false 表示已被移出
        return $isMember !== false && $isMember !== '0';
    }

    /**
     * getChatMember 报错时，是否属于「机器人不是群成员」
     */
    private function isNotMemberError(string $message): bool
    {
        return (bool) preg_match(
            '/(member\s+not\s+found|user\s+not\s+found|chat\s+not\s+found|bot\s+is\s+not\s+a\s+member|PARTICIPANT_ID_INVALID|CHANNEL_INVALID|CHAT_ID_INVALID)/i',
            $message
        );
    }

    /**
     * 候选 chat_id：库里出现过的所有群（含其它机器人 / 真人号同步进来的）
     */
    private function candidateChatIds(): array
    {
        $fromGroups = BotGroups::query()->pluck('chat_id')->toArray();
        $fromAdmins = GroupAdmins::query()->pluck('chat_id')->toArray();

        $chatIds = array_values(array_unique(array_filter(
            array_map(static fn ($chatId) => (string) $chatId, array_merge($fromGroups, $fromAdmins)),
            static fn ($chatId) => $chatId !== ''
        )));

        return array_slice($chatIds, 0, self::MAX_CANDIDATES);
    }

    /**
     * 写入 / 更新群信息
     *
     * @throws TelegramSDKException
     */
    private function upsertGroup(Api $telegram, Bots $bot, string $chatId): void
    {
        $chatData = $telegram->getChat(['chat_id' => $chatId]);

        $type = (string) ($chatData->type ?? '');

        // 只同步群/频道，私聊不入库
        if (! in_array($type, ['group', 'supergroup', 'channel'], true)) {
            return;
        }

        BotGroups::updateOrCreate(
            [
                'chat_id' => $chatId,
                'bot_id'  => $bot->id,
            ],
            [
                'name' => $chatData->title ?? '',
                'title' => $chatData->title ?? '',
                'type' => $type,
                'description' => $chatData->description ?? '',
                'invite_link' => $chatData->inviteLink ?? '',
                'enabled' => 1,
                'creator_id' => $bot->creator_id ?: 1,
            ]
        );

        Cache::forget("telegram:chat:synced:{$bot->id}:{$chatId}");
    }

    /**
     * 机器人不在群里时，移除它对这个群的绑定
     */
    private function detachGroup(Bots $bot, string $chatId): bool
    {
        $deleted = BotGroups::where('chat_id', $chatId)
            ->where('bot_id', $bot->id)
            ->forceDelete();

        \Modules\Telegram\Models\FeaturesBinds::where('chat_id', $chatId)
            ->where('bot_id', $bot->id)
            ->forceDelete();

        return $deleted > 0;
    }

    /**
     * 清理历史脏数据：bot_id 被写成了 Telegram 的 bot uid（如 7123456789）
     */
    private function purgeLegacyRows(Bots $bot, int|string $botTelegramId): void
    {
        if ((string) $botTelegramId === (string) $bot->id) {
            return;
        }

        BotGroups::where('bot_id', (string) $botTelegramId)->forceDelete();
    }
}
