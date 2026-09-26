<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Command;

use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\ServicePeoples;
use Modules\Telegram\Models\TelegramApiUsers;
use Modules\Telegram\Services\LogMessageService;

/**
 * 绑定客服：/bdcs <phone>
 */
class BindCustomer extends BaseSlashCommand
{
    public function __construct(
        protected readonly BotGroups $botGroups,
        protected readonly TelegramApiUsers $telegramApiUsers,
        protected readonly ServicePeoples $servicePeoples,
        protected readonly LogMessageService $logMessageService
    ) {}

    public function name(): string
    {
        return 'bdcs';
    }

    public function description(): string
    {
        return '按手机号绑定客服到当前群';
    }

    public function params(): array
    {
        return [
            [
                'name' => 'phone',
                'required' => true,
                'description' => '客服手机号',
            ],
        ];
    }

    public function handle(CommandContext $context): string
    {
        $botGroup = $this->botGroups->where('chat_id', $context->chatId)->first();

        if (! $botGroup) {
            return 'Error: Chat group not found. Please make sure the bot is added to this group.';
        }

        $phone = (string) $context->arg(0);

        $telegramUser = $this->telegramApiUsers->where('phone_number', $phone)->first();

        if (! $telegramUser) {
            return "Error: Customer service user with phone {$phone} not found.";
        }

        try {
            // service_peoples.app_id 存的是 telegram_api_users.app_id 字符串，
            // 与 SyncUserGroupService::syncBotGroups() 的写法保持一致
            $this->servicePeoples->updateOrCreate(
                [
                    'group_id' => $botGroup->id,
                    'app_id' => $telegramUser->app_id,
                ],
                [
                    'creator_id' => 1,
                ]
            );

            return '✅ Successfully bound customer service.';
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'commandError',
                ['phone' => $phone, 'chat_id' => $context->chatId, 'error' => $e->getMessage()],
                'BindCustomer error'
            );

            return 'Error: Failed to bind customer service.';
        }
    }
}
