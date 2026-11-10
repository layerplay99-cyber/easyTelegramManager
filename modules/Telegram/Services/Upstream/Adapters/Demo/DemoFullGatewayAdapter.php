<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream\Adapters\Demo;

use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Upstream\Adapters\BaseUpstreamAdapter;
use Modules\Telegram\Services\Upstream\UpstreamRequest;
use Modules\Telegram\Services\Upstream\UpstreamResponse;
use Modules\Telegram\Services\Upstream\UpstreamResult;

/**
 * 【教学示例 4 / 4】完整插件：按接口分别处理请求与响应
 *
 * 现实中一家上游往往有多个接口，每个接口的参数名和返回结构都不同。
 * 推荐写法是「一个插件类 + 按 $request->endpointCode 分派」：
 *   - 一家厂商一个文件，好找好维护
 *   - 公共部分（签名、token、业务码判定）抽私有方法复用
 *   - 每个接口的差异集中在一个 match 分支里
 *
 * 假设「示例丙」有两个接口：
 *
 *   merchant.balance      GET  余额：返回 result.wallet.avl / result.wallet.ccy
 *   merchant.trade.query  POST 查单：请求要 tradeNo + sign，
 *                              返回 result.rows[0].status / .fee（单位分）
 *
 * 平台标准结构（功能代码和模板只读这个）：
 *   merchant.balance      → {data: {balance, currency}}
 *   merchant.trade.query  → {data: {status, order_no, amount}}
 *
 * ⚠️ 教学示例，不要在生产环境的三方配置里选中它。
 */
class DemoFullGatewayAdapter extends BaseUpstreamAdapter
{
    public static function key(): string
    {
        return 'demo.full_gateway';
    }

    public static function label(): string
    {
        return '示例 4 · 完整多接口网关（示例丙）';
    }

    public static function description(): string
    {
        return '教学：一个厂商多个接口，按 endpointCode 分派，覆盖请求签名、嵌套解包、状态映射、金额换算。';
    }

    // =================================================================
    // 请求侧
    // =================================================================
    public function prepareRequest(UpstreamRequest $request, ThirdApiConfig $upstream): UpstreamRequest
    {
        // 公共：这家厂商所有接口都要带 appKey + 时间戳，并放同一个头
        $request
            ->setParam('appKey', (string) ($upstream->public_key ?? ''))
            ->setParam('ts', time())
            ->putHeader('X-Vendor', 'demo-c');

        // 按接口分派：不同接口的参数名不一样
        match ($request->endpointCode) {
            'merchant.balance' => $request->renameParams([
                'merchant_id' => 'mchNo',
            ]),
            'merchant.trade.query' => $this->prepareTradeQuery($request, $upstream),
            default => null, // 其它接口保持平台标准名，上游刚好也认
        };

        return $request;
    }

    /**
     * 查单接口：改名 + 补签名
     */
    private function prepareTradeQuery(UpstreamRequest $request, ThirdApiConfig $upstream): void
    {
        $request->renameParams([
            'merchant_id' => 'mchNo',
            'trade_id' => 'tradeNo',
        ]);

        $request->setMethod('POST')->setBodyMode('json');

        // 签名示例：HMAC-SHA256（按字典序拼 key=value），凭证取上游配置
        $payload = $request->params;
        ksort($payload);
        $pairs = [];
        foreach ($payload as $k => $v) {
            $pairs[] = $k . '=' . (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v);
        }
        $request->setParam(
            'sign',
            hash_hmac('sha256', implode('&', $pairs), (string) ($upstream->secrept_key ?? ''))
        );
    }

    // =================================================================
    // 响应侧
    // =================================================================
    protected function normalize(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult {
        $raw = $response->payload();
        $code = $this->businessCode($response);

        // 公共：这家厂商所有接口都用 code=0 表示成功
        if (! $this->businessOk($response, ['code'], [0, '0', 'SUCCESS'])) {
            return UpstreamResult::fail(
                $this->businessMessage($response),
                $raw,
                $code,
                $response->httpStatus
            );
        }

        // 按接口归一到平台标准结构
        $standard = match ($request->endpointCode) {
            'merchant.balance' => $this->normalizeBalance($response),
            'merchant.trade.query' => $this->normalizeTradeQuery($response, $request),
            default => ['data' => $this->unwrap($response)],
        };

        return UpstreamResult::ok($standard, $raw, $code, $response->httpStatus);
    }

    /**
     * 余额：三层嵌套 result.wallet，字段名 avl / ccy
     */
    private function normalizeBalance(UpstreamResponse $response): array
    {
        $wallet = $response->get('result.wallet');
        $wallet = is_array($wallet) ? $wallet : [];

        return [
            'data' => [
                'balance' => $wallet['avl'] ?? $wallet['available'] ?? null,
                'currency' => $wallet['ccy'] ?? $wallet['currency'] ?? null,
            ],
        ];
    }

    /**
     * 查单：返回是列表 result.rows[0]，金额单位是分，状态是厂商枚举
     */
    private function normalizeTradeQuery(UpstreamResponse $response, UpstreamRequest $request): array
    {
        $row = $response->get('result.rows.0');
        $row = is_array($row) ? $row : [];

        return [
            'data' => [
                'status' => $this->mapStatus($row['status'] ?? ''),
                'order_no' => $row['outTradeNo'] ?? $request->params['tradeNo'] ?? '',
                'amount' => $this->fenToYuan($row['fee'] ?? null),
            ],
        ];
    }

    private function mapStatus(mixed $value): string
    {
        return match (strtoupper(trim((string) $value))) {
            'PAID', 'SUCCESS', 'SETTLED', 'COMPLETED' => 'success',
            'FAILED', 'CLOSED', 'REFUNDED', 'CANCELLED' => 'failed',
            default => 'pending',
        };
    }
}
