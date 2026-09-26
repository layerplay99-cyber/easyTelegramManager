<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Command;

use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\ApiHookServices\AiopayService;
use Modules\Telegram\Services\LogMessageService;

/**
 * 查询余额：/ye
 *
 * 后台配置项：thridconfig（上游 API 配置 ID）
 */
class GetBalance extends BaseSlashCommand
{
    private const LOG_FILE = 'commandError';

    protected string $apiUrl = '/api/webhook/users/';

    public function __construct(
        protected readonly AiopayService $aiopayService,
        protected readonly LogMessageService $logMessageService
    ) {}

    public function name(): string
    {
        return 'ye';
    }

    public function description(): string
    {
        return '查询商户余额';
    }

    public function configSchema(): array
    {
        return [
            [
                'key' => 'thridconfig',
                'label' => '上游 API 配置',
                'type' => 'select',
                'required' => true,
                'options' => ThirdApiConfig::query()
                    ->get(['id', 'name'])
                    ->map(fn ($item) => ['label' => $item->name, 'value' => (string) $item->id])
                    ->toArray(),
            ],
        ];
    }

    public function handle(CommandContext $context): string
    {
        $thirdApi = $this->thirdApiConfig($context);
        $groupConfig = $this->groupConfig($context);

        if ($thirdApi === null || $groupConfig === null) {
            $context->log('GetBalance: 上游 API 或群配置缺失');

            return $this->failed();
        }

        $response = $this->aiopayService->get(
            $this->apiUrl . ($groupConfig['mid'] ?? ''),
            $thirdApi->api_url,
            $thirdApi->api_token
        );

        if (! $response->successful()) {
            $context->log('GetBalance: 上游请求失败', ['status' => $response->status()], 'warning');

            return $this->failed();
        }

        return $this->successful($response->json());
    }

    private function successful(array $data): string
    {
        $funds = number_format((float) data_get($data, 'data.available_funds', 0), 2);
        $currency = data_get($data, 'data.currency.code', '');

        return sprintf("Balance: %s %s\n余额： %s %s", $funds, $currency, $funds, $currency);
    }
}
