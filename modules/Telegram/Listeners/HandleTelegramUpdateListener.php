<?php

namespace Modules\Telegram\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\App;
use Modules\Telegram\Events\TelegramUpdateReceivedEvent;
use Modules\Telegram\Services\Feature\ListenBotInGroupService;
use Modules\Telegram\Services\Feature\TelegramFeatureService;

// 必须实现 ShouldQueue（队列 worker 已在跑），但关键是不能在构造函数注入服务：
// 若注入 ListenBotInGroupService（含未初始化的强类型属性 Api $telegram 等），
// 监听器被序列化入队时，未初始化的非可空属性会触发
// 「Serialization of uninitialized non-nullable property」，导致 event() 抛异常、
// 任务根本没进队列、群永远同步不进来。服务改为在 handle() 内即时解析。
readonly class HandleTelegramUpdateListener implements ShouldQueue
{
    public function handle(TelegramUpdateReceivedEvent $event)
    {
        try {
            $update = $event->update;
            $bot = $event->bot;

            if (! $bot) {
                app('logMessageService')->createLaravelLog("telegram_error", [
                    'update' => $update ?? null,
                ], 'Webhook 未匹配到机器人，跳过处理', 'warning');

                return;
            }

            // 延迟解析：监听器实例不持有任何服务属性，可被干净序列化入队。
            $botInGroupService = App::make(ListenBotInGroupService::class);

            // 兜底同步：机器人收到的任何 update 都要保证群信息已入库。
            // 这样「先拉机器人进群、后在后台登记机器人」也能自动补上群。
            $resolver = App::make(\Modules\Telegram\Services\Feature\UpdateTypeResolver::class);

            if ($chatId = $resolver->extractChatId($update)) {
                $botInGroupService->ensureChatSynced($bot, $chatId);
            }

            // 统一的分发链路：成员事件 / 斜杠命令 / 按钮、图片等全部交给路由器，
            // 过滤规则集中在 UpdateRouter + UpdateTypeResolver 里，
            // 这里不再硬编码「放行 text/photo/callback_query」。
            App::make(\Modules\Telegram\Services\Feature\UpdateRouter::class)->route($bot, $update);
        } catch (\Throwable $e) {
            // 记录错误日志
            app('logMessageService')->createLaravelLog("telegram_error", [
                'trace' => $e->getTraceAsString(),
                'update' => $event->update ?? null,
                'bot_id' => $event->bot ? $event->bot->id : null,
            ], 'Telegram Update Handling Error: ' . $e->getMessage());
        }
    }

    /**
     * 从 update 中提取 chat_id
     */
    private function extractChatId($update)
    {
        return match(true) {
            $update->getMessage() !== null => $update->getMessage()->chat->id ?? null,
            $update->isType('callback_query') && isset($update->callbackQuery)
                => $update->callbackQuery->message->chat->id ?? null,
            isset($update->myChatMember) => $update->myChatMember->chat->id ?? null,
            isset($update->chatMember) => $update->chatMember->chat->id ?? null,
            default => null
        };
    }

    private function isGroupMembershipEvent($update): bool
    {
        return $update->has('my_chat_member') ||
            (isset($update?->message->new_chat_members) ||
             isset($update?->message->left_chat_member));
    }
}
