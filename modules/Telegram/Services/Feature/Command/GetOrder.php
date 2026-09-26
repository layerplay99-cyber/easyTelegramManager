<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Command;

use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\ApiHookServices\AiopayService;
use Modules\Telegram\Services\LogMessageService;

/**
 * 查询订单：/cx <order_id>
 *
 * 后台配置项：
 *   - thridconfig 上游 API 配置
 *   - rule        订单号校验正则（留空则不校验）
 */
class GetOrder extends BaseSlashCommand
{
    private const DEFAULT_RULE = '/^[A-Za-z0-9_-]+$/';

    protected string $apiUrl = '/api/webhook/users/:userID/trades/:id';

    public function __construct(
        protected readonly AiopayService $aiopayService,
        protected readonly LogMessageService $logMessageService
    ) {}

    public function name(): string
    {
        return 'cx';
    }

    public function description(): string
    {
        return '按订单号查询交易';
    }

    /**
     * 声明参数，分发器会自动做必填与正则校验，
     * 校验失败直接把 usage 回给用户，命令类不用再写这些样板代码。
     */
    public function params(): array
    {
        return [
            [
                'name' => 'order_id',
                'required' => true,
                'description' => '订单号',
                // 默认规则；后台可在 config 里用 {"rules":{"order_id":"..."}} 覆盖
                'rule' => self::DEFAULT_RULE,
                'message' => 'Invalid order ID format.',
            ],
        ];
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
            [
                'key' => 'rules.order_id',
                'label' => '订单号校验正则',
                'type' => 'text',
                'required' => false,
                'default' => self::DEFAULT_RULE,
            ],
        ];
    }

    public function handle(CommandContext $context): string
    {
        $thirdApi = $this->thirdApiConfig($context);
        $groupConfig = $this->groupConfig($context);

        if ($thirdApi === null || $groupConfig === null) {
            $context->log('GetOrder: 上游 API 或群配置缺失');

            return $this->failed();
        }

        $orderId = (string) $context->arg(0);

        $url = str_replace(
            [':userID', ':id'],
            [$groupConfig['mid'] ?? '', $orderId],
            $this->apiUrl
        );

        $response = $this->aiopayService->post($url, $thirdApi->api_url, $thirdApi->api_token);

        if (! $response->successful()) {
            $context->log('GetOrder: 上游请求失败', ['status' => $response->status()], 'warning');

            return $this->failed();
        }

        $lang = json_decode($groupConfig['replyLang'] ?? '[]', true) ?: ['zh_CN'];

        return $this->formatMultilang($response->json(), $lang);
    }
}
