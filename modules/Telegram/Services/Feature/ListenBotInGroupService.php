<?php

namespace Modules\Telegram\Services\Feature;

use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\GroupAdmins;
use Modules\Telegram\Models\GroupMembers;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Modules\Telegram\Services\FeatureOperateService;
use Modules\Telegram\Services\Group\GroupCleanupService;
use Modules\Telegram\Services\GroupWelcomeService;
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

            // ⚠️ 不能写 $update->has('xxx')：这个 SDK 的 Update/BaseObject 没有 has() 方法，
            //    未定义的方法会落到 BaseObject::__call()，而它对非 get 开头的方法
            //    一律 `return false` —— 判断恒为 false，下面所有分支从来没进去过
            //    （机器人被踢出群却不清数据，就是这个原因）。
            //    正确写法：属性 isset()（BaseObject 有 __isset/__get）或 getXxx()。
            if ($update->getMessage() !== null) {
                $this->handleMessage($bot, $update->message);
            }

            // 机器人自己的成员状态变化：进群 / 被踢 / 权限变更
            if (isset($update->myChatMember)) {
                $this->handleMyChatMember($bot, $update->myChatMember);
            }

            // chat_member：群成员状态变化（机器人关掉隐私模式时，自己的变化也走这里）。
            // 只处理「离开/被踢」：群里任何人的进出都会发这条，若走完整的入群同步，
            // 会平白多出 getChat + getChatAdministrators 两轮 API 调用。
            // handleBotKicked() 内部会校验被踢的是不是本机器人，别人退群不会误删群。
            if (isset($update->chatMember)) {
                $chatMember = $update->chatMember;

                if (in_array($chatMember->new_chat_member->status ?? null, ['kicked', 'left'], true)) {
                    $this->handleBotKicked(
                        $bot,
                        $chatMember->chat->id ?? null,
                        $chatMember->new_chat_member->user ?? null
                    );
                }
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

        // 刚被删掉的群（机器人被踢 / 后台人工删除）不再自动重建，
        // 否则只要机器人还在群里，下一条 update 就会把它拉回来 —— 表现为"删了又冒出来"。
        // 机器人重新入群时 handleMyChatMember() 会清掉这个标记。
        if (app(GroupCleanupService::class)->isDeleted($chatId, $bot->id)) {
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

            // 只登记群 + 打节流标记，不做功能绑定。
            //
            // 功能是「机器人白名单 > 群子集」的递进关系：新发现的群只入库，
            // 用哪些功能由后台在群里手动添加。这里调 syncBindFeature() 会把机器人
            // 的 bot 类功能全部按 enabled=1 写进这个群，等于新群自动全选，
            // 与「没配置的群即便机器人有也不能用」冲突。
            if (! $exists) {
                $exists = $this->syncChatInfo($chatId, $bot->id);
            }

            // 同步失败就不打节流标记，下一条消息再试一次
            if (! $exists) {
                return;
            }

            // 不在这里同步管理员：权限判定以官方 getChatMember 为准（见 OperatorPolicy），
            // group_admins 只是接口不可用时的备份，靠 bot 入群事件 + 判定成功时回填维护。
            // 没必要为了填这张表给每个群每 10 分钟多打一次 getChatAdministrators。
            Cache::put($cacheKey, true, now()->addMinutes(self::SYNC_THROTTLE_MINUTES));
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
                    // 普通成员入群：保存成员信息 + 发进群欢迎语
                    $this->saveMember($chatId, $member);
                    $this->sendWelcome($chatId, $member);
                }
            }
        }

        // 成员离开群
        if (!empty($message->left_chat_member)) {
            $member = $message->left_chat_member;

            // ⚠️ 顺序不能反：踢出清理必须先跑。
            //    markMemberLeft() 写 left_at 时抛过 SQL 1265（见其注释），
            //    异常被 handleUpdate() 的 catch 接住，后面的 handleBotKicked()
            //    一次都执行不到 —— 表现为「机器人被踢了，群列表却还在」。
            //    另外 handleBotKicked() 会连 group_members 一起删，
            //    先删再标记最多是更新 0 行，反过来就会漏删整个群。
            //
            // 机器人被踢出群（注意：可能是别的机器人被踢）
            if ($member->is_bot) {
                $this->handleBotKicked($bot, $chatId, $member);
            }

            $this->markMemberLeftSafely($chatId, $member);
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

            // ⚠️ 不要在这里调 syncBindFeature()：它会把系统里所有 enabled=1 的
            //    bot 类功能按 enabled=1 写进这个群（而且不按 bot_id 过滤），
            //    等于新群自动全选，与「机器人白名单 > 群子集」冲突 ——
            //    机器人没配功能、群里却显示一堆已配置功能就是这么来的。
            //    入群只登记群，用哪些功能由后台在群里手动添加。
            //    （ensureChatSynced() 里同样不调，见那里更详细的说明）

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
            // 明确的入群信号：清掉「已删除」标记，允许这个群重新入库
            // （之前被踢过或后台删过，墓碑会一直拦着 ensureChatSynced）
            app(GroupCleanupService::class)->clearDeletedMark($chatId, $bot->id);

            $this->syncChatInfo($chatId, $bot->id);
            $this->syncChatAdmins($chatId);

            // 同上：不自动绑功能，保持「机器人白名单 > 群子集」
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
            //
            // creator_id 继承「所属机器人」的归属人：
            // 群列表是跟着机器人走的，机器人归属部门 A，群就该只对部门 A 可见。
            // 这里跑在 webhook / 队列里，没有登录用户，不显式写就是 0；
            // 而 creator_id = 0 属于「无主数据」，在 catch.data_scope_include_unowned=true
            // 时对所有后台用户可见 —— 部门管理员会看到超管机器人的群。
            $botCreatorId = (int) (($this->bot?->creator_id)
                ?: (Bots::query()->whereKey($botId)->value('creator_id') ?: 0));

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
                    'creator_id' => $botCreatorId,
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
                // 见 markMemberLeft()：这两列是 unix int，只写整数时间戳
                'joined_at' => now()->timestamp,
                'left_at' => null,
            ]
        );
    }

    /**
     * 进群欢迎语
     *
     * 后台「群配置 - 欢迎语」录入中文，发送时按群语种补一段对应语种。
     * 没配欢迎语就什么都不发；发失败也不影响成员入库。
     */
    protected function sendWelcome($chatId, $member): void
    {
        try {
            $telegram = $this->telegram ?? app(BotApiFactory::class)->forBot($this->bot);

            app(GroupWelcomeService::class)->send($telegram, $chatId, $member);
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_errors',
                [
                    'chat_id' => $chatId,
                    'trace' => $e->getTraceAsString(),
                ],
                "处理进群欢迎语失败: {$e->getMessage()}",
                'error'
            );
        }
    }

    /**
     * 标记成员离开群组（失败只记日志，不向上抛）
     *
     * 成员进出群是「记账」性质，写失败不值得连累同一次 update 里的其它处理
     * （历史上它抛的 SQL 1265 直接把机器人被踢的清理逻辑拦掉了）。
     */
    protected function markMemberLeftSafely($chatId, $member): void
    {
        try {
            $this->markMemberLeft($chatId, $member);
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_errors',
                [
                    'chat_id' => $chatId,
                    'user_id' => $member->id ?? null,
                    'trace' => $e->getTraceAsString(),
                ],
                "标记成员离开群组失败: {$e->getMessage()}",
                'warning'
            );
        }
    }

    /**
     * 标记成员离开群组
     *
     * ⚠️ left_at 是 unix int 列（迁移 fix_timestamp_columns_to_unix_int 改的），
     *    这里走的是 Query Builder 的 update()，不经过 Eloquent，
     *    模型上的 getDates()/日期 cast 对它一律无效 —— 传 now()（Carbon）
     *    会被 PDO 直接格式化成 'Y-m-d H:i:s' 写进 int 列，MySQL 报
     *    「1265 Data truncated」。必须自己传整数时间戳。
     */
    protected function markMemberLeft($chatId, $member): void
    {
        GroupMembers::where('chat_id', $chatId)
            ->where('user_id', $member->id)
            ->update([
                'status' => 'left',
                'left_at' => now()->timestamp,
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
                // 被踢的是别的机器人，只标记成员离开即可。
                //
                // 这条日志同时用来兜「webhook 匹配错机器人」：url_token 重复时
                // WebHookController::where('url_token', ...)->first() 只会取到第一条，
                // 被踢的机器人和 $bot 对不上，群里数据就永远删不掉。
                $this->logMessageService->createLaravelLog(
                    'telegram_events',
                    [
                        'chat_id' => $chatId,
                        'bot_id' => $bot->id,
                        'kicked_user_id' => $member->id,
                        'self_user_id' => $selfId,
                    ],
                    '群里有别的机器人被移出，与本机器人无关，跳过清理',
                    'info'
                );

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

        // 群本体 + 这个群的所有配置/绑定一起清（只删 bot_groups 会留一堆孤儿数据）：
        //   group_configs、service_peoples、group_admins、group_members、
        //   features_binds、merchant_routes、interactive_sessions、feature_data(群级)
        // bot 维度的数据只删本机器人的，群里还有别的机器人时不误伤。
        $deleted = app(GroupCleanupService::class)->deleteGroup($chatId, $bot->id);

        // 一条都没删掉：群里多半没有「bot_id = 本机器人」的行（群挂在了别的机器人名下，
        // 或压根没入库）。这种静默失败最难查，留一条带现场数据的日志。
        if (($deleted['groups'] ?? 0) === 0) {
            $this->logMessageService->createLaravelLog(
                'telegram_errors',
                [
                    'chat_id' => $chatId,
                    'bot_id' => $bot->id,
                    'rows_in_db' => \Modules\Telegram\Models\BotGroups::where('chat_id', $chatId)
                        ->get(['id', 'bot_id', 'chat_id'])
                        ->toArray(),
                ],
                "机器人被踢出群，但未匹配到要删除的群记录",
                'warning'
            );
        }

        $this->logMessageService->createLaravelLog(
            'telegram_events',
            [
                'chat_id' => $chatId,
                'bot_id' => $bot->id,
                'bot_username' => $member ? ($member->username ?? '') : '',
                'deleted' => $deleted,
            ],
            "机器人被踢出群，已删除群及关联数据",
        );
    }

    // 原来这里有个 syncBindFeature()，入群时把 bot 类功能全量绑到群上，
    // 与「机器人白名单 > 群子集」冲突，已移除（详见 handleBotJoined() 的注释）。
    // 群要用哪些功能，由后台在群里手动添加。
}
