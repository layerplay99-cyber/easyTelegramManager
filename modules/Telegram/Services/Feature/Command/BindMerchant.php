<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Command;

use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\GroupConfigs;
use Modules\Telegram\Services\LogMessageService;

/**
 * 绑定商户号：/bm <merchant_id>
 */
class BindMerchant extends BaseSlashCommand
{
    public function __construct(
        protected readonly BotGroups $botGroups,
        protected readonly GroupConfigs $groupConfigs,
        protected readonly LogMessageService $logMessageService
    ) {}

    public function name(): string
    {
        return 'bm';
    }

    public function description(): string
    {
        return '绑定商户号到当前群';
    }

    public function params(): array
    {
        return [
            [
                'name' => 'merchant_id',
                'required' => true,
                'description' => '商户号',
            ],
        ];
    }

    public function handle(CommandContext $context): string
    {
        $botGroup = $this->botGroups->where('chat_id', $context->chatId)->first();

        if (! $botGroup) {
            return 'Error: Chat group not found. Please make sure the bot is added to this group.';
        }

        try {
            $this->groupConfigs->updateOrCreate(
                ['groupId' => $botGroup->id],
                ['mid' => (string) $context->arg(0)]
            );

            return '✅ Successfully bound MerchantId.';
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'commandError',
                ['merchant_id' => $context->arg(0), 'chat_id' => $context->chatId, 'error' => $e->getMessage()],
                'BindMerchant error'
            );

            return 'Error: Failed to bind merchantId.';
        }
    }
}
