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

            // 延迟解析：监听器实例不再持有任何服务属性，可被干净序列化入队。
            $botInGroupService = App::make(ListenBotInGroupService::class);
            $featureService = App::make(TelegramFeatureService::class);

            // 兜底同步：机器人收到的任何 update 都要保证群信息已入库。
            // 这样「先拉机器人进群、后在后台登记机器人」也能自动补上群。
            if ($chatId = $this->extractChatId($update)) {
                $botInGroupService->ensureChatSynced($bot, $chatId);
            }

            if ($this->isGroupMembershipEvent($update)) {
                $botInGroupService->handleUpdate($bot, $update);
            } else {
                // 文本消息（含所有斜杠命令）、图片、按钮回调都交给功能分发。
                // 原来这里只放行 photo / callback_query，导致 message.text 被排除，
                // 斜杠命令永远进不了功能分发（后台配了命令也从不触发）。
                $isTextMessage = $update->getMessage() !== null
                    && $update->getMessage()->has('text');

                if ($isTextMessage
                    || ($update->getMessage() && $update->getMessage()->has('photo'))
                    || $update->isType('callback_query')
                ) {
                    $featureService->handleInteraction($bot, $update);
                }
            }
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
