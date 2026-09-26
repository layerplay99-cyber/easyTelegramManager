<?php

namespace Modules\Telegram\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Telegram\Events\TelegramUpdateReceivedEvent;
use Modules\Telegram\Services\Feature\ListenBotInGroupService;
use Modules\Telegram\Services\Feature\TelegramFeatureService;

readonly class HandleTelegramUpdateListener implements ShouldQueue
{
    public function __construct(
        public ListenBotInGroupService $botInGroupService,
        public TelegramFeatureService  $featureService
    )
    {}
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

            // 兜底同步：机器人收到的任何 update 都要保证群信息已入库。
            // 这样「先拉机器人进群、后在后台登记机器人」也能自动补上群。
            if ($chatId = $this->extractChatId($update)) {
                $this->botInGroupService->ensureChatSynced($bot, $chatId);
            }

            if($this->isGroupMembershipEvent($update)) {
                $this->botInGroupService->handleUpdate($bot, $update);
            } elseif(($update->getMessage() && $update->getMessage()->has('photo')) || $update->isType('callback_query')) {
                $this->featureService->handleInteraction($bot, $update);
            }
        } catch (\Throwable $e) {
            // 记录错误日志
            app('logMessageService')->createLaravelLog("telegram_error", [
                'trace' => $e->getTraceAsString(),
                'update' => $update ?? null,
                'bot_id' => $bot ? $bot->id : null,
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
