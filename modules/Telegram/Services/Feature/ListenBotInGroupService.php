<?php

namespace Modules\Telegram\Services\Feature;

use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Models\FeaturesBinds;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\GroupAdmins;
use Modules\Telegram\Models\GroupMembers;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Modules\Telegram\Services\FeatureOperateService;
use Modules\Telegram\Services\LogMessageService;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

class ListenBotInGroupService
{
    protected Api $telegram;
    protected LogMessageService $logMessageService;

    protected FeatureOperateService $featureOperateService;

    /**
     * 当前处理中的 bot（bots 表记录）
     */
    protected $bot = null;

    /**
     * 群信息同步节流：同一个 bot 的同一个群，N 分钟内不重复回写数据库
     */
    private const SYNC_THROTTLE_MINUTES = 10;

    /**
     * 需要入库的会话类型（私聊不入库）
     */
    private const GROUP_CHAT_TYPES = ['group', 'supergroup', 'channel'];

    public function __construct()
    {
        $this->logMessageService = app(LogMessageService::class);
        $this->featureOperateService = app(FeatureOperateService::class);
    }

    /**
     * 处理 Telegram 更新
     * @throws TelegramSDKException
     */
    public function handleUpdate($bot, $update)
    {
        if (! $bot || empty($bot->api_token)) {
            $this->logMessageService->createLaravelLog(
                'telegram_errors',
                ['update' => $update],
                '未匹配到机器人（url_token 不匹配或 api_token 为空），跳过处理',
                'warning'
            );

            return;
        }

        $this->bot = $bot;

        try {
            // 先初始化 telegram API（使用传入的 bot）
            $this->telegram = app(BotApiFactory::class)->forBot($bot);

            if ($update->has('message')) {
                $this->handleMessage($bot, $update->message);
            }

            if ($update->has('my_chat_member')) {
                $this->handleMyChatMember($bot, $update->my_chat_member);
            }
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_errors',
                ['trace' => $e->getTraceAsString(), 'update' => $update, 'bot_id' => $bot ? $bot->id : null],
                "处理 Telegram 更新失败: {$e->getMessage()}",
            );
        }
    }

    /**
     * 兜底同步：机器人收到任意 update 时，确保群信息已入库
     *
     * 解决「先加群、后在后台登记机器人」导致群里永远不出现的问题——
     * Bot API 没有列出机器人所在群的接口，只能借助收到的 update 自愈。
     */
    public function ensureChatSynced($bot, $chatId): void
    {
        if (! $bot || empty($bot->api_token) || ! $chatId) {
            return;
        }

        $cacheKey = "telegram:chat:synced:{$bot->id}:{$chatId}";

        if (Cache::has($cacheKey)) {
            return;
        }

        try {
            $this->bot = $bot;
            $this->telegram = app(BotApiFactory::class)->forBot($bot);

            $exists = BotGroups::where('chat_id', $chatId)
                ->where('bot_id', $bot->id)
                ->exists();

            // 已存在只做节流标记，避免每条消息都写库
            if ($exists) {
                Cache::put($cacheKey, true, now()->addMinutes(self::SYNC_THROTTLE_MINUTES));
                return;
            }

            if ($this->syncChatInfo($chatId, $bot->id)) {
                Cache::put($cacheKey, true, now()->addMinutes(self::SYNC_THROTTLE_MINUTES));

                $this->syncBindFeature($chatId, $bot->id);
            }
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_errors',
                ['chat_id' => $chatId, 'bot_id' => $bot->id, 'trace' => $e->getTraceAsString()],
                "兜底同步群信息失败: {$e->getMessage()}",
            );
        }
    }

    /**
     * 处理消息类型更新
     */
    protected function handleMessage($bot, $message)
    {
        $chatId = $message->chat->id;

        // 新成员入群
        if (!empty($message->new_chat_members)) {
            foreach ($message->new_chat_members as $member) {
                // 区分机器人和普通成员
                if ($member->is_bot) {
                    // 机器人入群：需要同步群信息和管理员
                    $this->handleBotJoined($bot, $chatId, $member);
                } else {
                    // 普通成员入群：只保存成员信息
                    $this->saveMember($chatId, $member);
                }
            }
        }

        // 成员离开群
        if (!empty($message->left_chat_member)) {
            $member = $message->left_chat_member;
            $this->markMemberLeft($chatId, $member);

            // 机器人被踢出群（注意：可能是别的机器人被踢）
            if ($member->is_bot) {
                $this->handleBotKicked($bot, $chatId, $member);
            }
        }
    }

    /**
     * 处理机器人加入群组
     */
    protected function handleBotJoined($bot, $chatId, $member): void
    {
        try {
            $this->logMessageService->createLaravelLog(
                'telegram_events',
                [
                    'chat_id' => $chatId,
                    'bot_id' => $member->id,
                    'bot_username' => $member->username ?? '',
                ],
                "机器人加入群组",
            );

            // 同步群信息（使用 bots 表主键，不能用 Telegram 的 bot uid）
            $this->syncChatInfo($chatId, $bot->id);

            // 尝试同步管理员信息（需要机器人有管理员权限）
            $this->syncChatAdmins($chatId);

            // 保存机器人成员记录
            $this->saveMember($chatId, $member);

            // 同步绑定功能到群组和机器人
            $this->syncBindFeature($chatId, $bot->id);

            Cache::put(
                "telegram:chat:synced:{$bot->id}:{$chatId}",
                true,
                now()->addMinutes(self::SYNC_THROTTLE_MINUTES)
            );

        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_errors',
                [
                    'chat_id' => $chatId,
                    'bot_id' => $member->id,
                    'trace' => $e->getTraceAsString()
                ],
                "处理机器人入群失败: {$e->getMessage()}",
            );
        }
    }

    /**
     * 处理 my_chat_member 更新
     */
    protected function handleMyChatMember($bot, $chatMember): void
    {
        $chatId = $chatMember->chat->id;
        $newStatus = $chatMember->new_chat_member->status;

        if (in_array($newStatus, ['kicked', 'left'])) {
            $member = $chatMember->new_chat_member->user;
            $this->handleBotKicked($bot, $chatId, $member);

            return;
        }

        // 原来只处理 administrator，机器人以普通成员身份进群时不会入库
        if (in_array($newStatus, ['member', 'administrator', 'creator', 'restricted'])) {
            $this->syncChatInfo($chatId, $bot->id);
            $this->syncChatAdmins($chatId);
            $this->syncBindFeature($chatId, $bot->id);
        }
    }

    /**
     * 同步群信息
     *
     * @param $chatId
     * @param $botId bots 表主键 id
     * @return bool 是否成功同步
     */
    protected function syncChatInfo($chatId, $botId = null): bool
    {
        try {
            $botId = $botId ?? $this->bot?->id;

            if (! $botId) {
                return false;
            }

            $chatData = $this->telegram->getChat(['chat_id' => $chatId]);
            $type = (string) ($chatData->type ?? '');

            // 私聊不入库
            if (! in_array($type, self::GROUP_CHAT_TYPES, true)) {
                return false;
            }

            // 必须把 bot_id 放进匹配条件，否则同一个群里多个机器人会互相覆盖归属
            BotGroups::updateOrCreate(
                [
                    'chat_id' => $chatId,
                    'bot_id'  => $botId,
                ],
                [
                    'name' => $chatData->title ?? '',
                    'title' => $chatData->title ?? '',
                    'type' => $type,
                    'description' => $chatData->description ?? '',
                    'invite_link' => $chatData->inviteLink ?? '',
                    'enabled' => 1,
                ]
            );

            return true;
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_errors',
                ['chat_id' => $chatId, 'trace' => $e->getTraceAsString()],
                "同步群信息失败: {$e->getMessage()}",
            );

            return false;
        }
    }

    /**
     * 同步群管理员信息
     * @param $chatId
     * @return void
     */
    protected function syncChatAdmins($chatId): void
    {
        try {
            $admins = $this->telegram->getChatAdministrators(['chat_id' => $chatId]);
            foreach ($admins as $admin) {
                $user = $admin->user;
                GroupAdmins::updateOrCreate(
                    [
                        'chat_id' => $chatId,
                        'user_id' => $user->id,
                    ],
                    [
                        'username' => $user->username ?? '',
                        'status' => $admin->status ?? '',
                        'can_delete_messages' => $admin->getCanDeleteMessages() ? 1 : 0,
                        'can_invite_users' => $admin->getCanInviteUsers() ? 1 : 0,
                        'can_promote_members' => $admin->getCanPromoteMembers() ? 1 : 0,
                    ]
                );
            }
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_errors',
                ['chat_id' => $chatId, 'trace' => $e->getTraceAsString()],
                "同步群管理员失败: {$e->getMessage()}",
            );
        }
    }

    /**
     * 保存成员信息
     */
    protected function saveMember($chatId, $member): void
    {
        GroupMembers::updateOrCreate(
            [
                'chat_id' => $chatId,
                'user_id' => $member->id,
            ],
            [
                'username' => $member->username ?? '',
                'status' => 'member',
                'is_bot' => $member->is_bot ? 1 : 0,
                'joined_at' => now(),
                'left_at' => null,
            ]
        );
    }

    /**
     * 标记成员离开群组
     */
    protected function markMemberLeft($chatId, $member): void
    {
        GroupMembers::where('chat_id', $chatId)
            ->where('user_id', $member->id)
            ->update([
                'status' => 'left',
                'left_at' => now(),
            ]);
    }

    /**
     * 处理机器人被踢出群组
     *
     * 注意：群里可能有多个机器人，被踢的必须是「本机器人」才能清数据，
     * 否则会把其它机器人的群记录一起删掉。
     */
    protected function handleBotKicked($bot, $chatId, $member = null): void
    {
        try {
            $telegram = $this->telegram ?? app(BotApiFactory::class)->forBot($bot);
            $selfId = $telegram->getMe()->getId();

            if ($member && (int) $member->id !== (int) $selfId) {
                // 被踢的是别的机器人，只标记成员离开即可
                return;
            }
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_errors',
                ['chat_id' => $chatId, 'trace' => $e->getTraceAsString()],
                "确认机器人身份失败: {$e->getMessage()}",
            );

            return;
        }

        // 只清理属于本机器人的绑定关系
        BotGroups::where('chat_id', $chatId)
            ->where('bot_id', $bot->id)
            ->forceDelete();

        FeaturesBinds::where('chat_id', $chatId)
            ->where('bot_id', $bot->id)
            ->forceDelete();

        GroupAdmins::where('chat_id', $chatId)->forceDelete();
        GroupMembers::where('chat_id', $chatId)->forceDelete();

        Cache::forget("telegram:chat:synced:{$bot->id}:{$chatId}");

        $this->logMessageService->createLaravelLog(
            'telegram_events',
            [
                'chat_id' => $chatId,
                'bot_id' => $bot->id,
                'bot_username' => $member ? ($member->username ?? '') : '',
            ],
            "机器人被踢出群，已删除相关数据",
        );
    }

    /**
     * 同步绑定功能到群组和机器人
     * @param $chatId
     * @param $botId
     * @return void
     */
    protected function syncBindFeature($chatId, $botId): void
    {
        $this->featureOperateService->bindFeature($chatId, $botId, 'bot');
    }
}
