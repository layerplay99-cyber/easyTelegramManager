<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Wallet;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Telegram\Contracts\WalletGateway;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Models\ThirdApiEndpoints;
use Modules\Telegram\Services\Feature\ThirdEndpointResolver;

/**
 * 默认钱包网关：按「平台接口规范 × 上游实例」调用上游
 *
 * 请求怎么发出去：
 *   1. 用 endpoint code（如 wallet.recharge.create）取平台接口规范；
 *   2. 用 ThirdEndpointResolver 取该上游对此接口的覆盖（path/method/headers/query/timeout），
 *      也就是后台「上游接口配置」里配的拉单 / 查单 API；
 *   3. 拼上上游的 key（public_key）、通道ID（channel_id），用密钥（secrept_key）签名；
 *   4. 发请求、按下面 map* 系列方法解析响应。
 *
 * 不同上游的字段名/状态值千奇百怪，接新上游时通常只需要改：
 *   sign()              签名规则（有的 md5、有的拼接 key 在后）
 *   mapRechargeCreate() 拉单返回里支付链接、上游单号取哪个字段
 *   mapQuery()          查单返回里状态、金额取哪个字段
 *   resolveStatus()     上游状态值 → 平台状态
 * 其余（订单、钱包、风控、功能代码）一行都不用动。
 */
class UpstreamWalletGateway implements WalletGateway
{
    public function __construct(protected ThirdEndpointResolver $resolver)
    {
    }

    public function createRecharge(ThirdApiConfig $upstream, array $params): array
    {
        return $this->request('wallet.recharge.create', $upstream, $params, 'mapRechargeCreate');
    }

    public function queryRecharge(ThirdApiConfig $upstream, array $params): array
    {
        return $this->request('wallet.recharge.query', $upstream, $params, 'mapQuery');
    }

    public function createWithdraw(ThirdApiConfig $upstream, array $params): array
    {
        return $this->request('wallet.withdraw.create', $upstream, $params, 'mapWithdrawCreate');
    }

    public function queryWithdraw(ThirdApiConfig $upstream, array $params): array
    {
        return $this->request('wallet.withdraw.query', $upstream, $params, 'mapQuery');
    }

    public function balance(ThirdApiConfig $upstream, array $params = []): array
    {
        return $this->request('wallet.balance', $upstream, $params, 'mapBalance');
    }

    public function verifyCallback(ThirdApiConfig $upstream, array $payload): bool
    {
        $secret = (string) ($upstream->secrept_key ?? '');

        // 没配密钥就一律拒绝：不允许"免签回调"入账
        if ($secret === '') {
            return false;
        }

        $sign = (string) ($payload['sign'] ?? $payload['signature'] ?? '');

        if ($sign === '') {
            return false;
        }

        return hash_equals(strtoupper($this->sign($payload, $secret)), strtoupper($sign));
    }

    public function resolveStatus(array $payload): string
    {
        $raw = strtolower((string) ($payload['status'] ?? $payload['state'] ?? ''));

        return match (true) {
            in_array($raw, ['1', '2', 'paid', 'success', 'succeed', 'completed', 'finish'], true) => 'paid',
            in_array($raw, ['failed', 'fail', '3', 'rejected', 'cancel'], true) => 'failed',
            default => 'pending',
        };
    }

    /**
     * 统一发请求
     */
    protected function request(string $endpointCode, ThirdApiConfig $upstream, array $params, string $mapper): array
    {
        $endpoint = ThirdApiEndpoints::query()->where('code', $endpointCode)->first();

        if (! $endpoint) {
            return $this->failed('平台未注册接口：' . $endpointCode);
        }

        $resolved = $this->resolver->resolve($upstream, $endpoint);

        if (! ($resolved['enabled'] ?? true)) {
            return $this->failed('上游未启用该接口：' . $endpointCode);
        }

        // 上游凭证：key / 通道ID
        $payload = array_merge($params, [
            'merchant_key' => $upstream->public_key ?? '',
            'channel_id' => $params['channel_id'] ?? $upstream->channel_id ?? '',
            'timestamp' => time(),
            'nonce' => bin2hex(random_bytes(8)),
        ]);

        $payload['sign'] = $this->sign($payload, (string) $upstream->secrept_key);

        $url = $this->resolver->buildUrl($upstream, $this->renderPath($resolved['path'], $payload));
        $method = strtoupper((string) ($resolved['method'] ?: $endpoint->method ?: 'POST'));

        try {
            $response = Http::timeout((int) ($resolved['timeout'] ?: 30))
                ->withHeaders($resolved['headers'] ?? [])
                ->{$method === 'GET' ? 'get' : 'post'}($url, $method === 'GET' ? [] : $payload);
        } catch (\Throwable $e) {
            Log::warning('[钱包网关] 请求上游失败', ['endpoint' => $endpointCode, 'error' => $e->getMessage()]);

            return $this->failed('请求上游失败：' . $e->getMessage());
        }

        if (! $response->successful()) {
            return $this->failed('上游返回 HTTP ' . $response->status(), ['raw' => $response->body()]);
        }

        $body = $response->json() ?? [];

        return $this->{$mapper}($body, $params);
    }

    /**
     * 签名：HMAC-SHA256（key=value 按字典序拼接 + 密钥）
     *
     * 上游规则不同就改这里；verifyCallback 会跟着一起变。
     */
    protected function sign(array $payload, string $secret): string
    {
        unset($payload['sign'], $payload['signature']);
        ksort($payload);

        $parts = [];

        foreach ($payload as $k => $v) {
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }

            $parts[] = $k . '=' . $v;
        }

        return strtoupper(hash_hmac('sha256', implode('&', $parts) . '&key=' . $secret, $secret));
    }

    protected function mapRechargeCreate(array $body, array $params): array
    {
        $data = $body['data'] ?? $body;

        return [
            'success' => $this->isOk($body),
            'status' => 'pending',
            'order_no' => $params['order_no'] ?? '',
            'third_order_no' => $data['third_order_no'] ?? $data['order_no'] ?? $data['trade_no'] ?? null,
            'pay_url' => $data['pay_url'] ?? $data['payUrl'] ?? $data['url'] ?? null,
            'amount' => $data['amount'] ?? $params['amount'] ?? null,
            'message' => $body['message'] ?? $body['msg'] ?? null,
            'raw' => $body,
        ];
    }

    protected function mapWithdrawCreate(array $body, array $params): array
    {
        $data = $body['data'] ?? $body;

        return [
            'success' => $this->isOk($body),
            'status' => in_array(strtolower((string) ($data['status'] ?? '')), ['success', 'completed', 'paid'], true)
                ? 'success' : 'pending',
            'order_no' => $params['order_no'] ?? '',
            'third_order_no' => $data['third_order_no'] ?? $data['order_no'] ?? null,
            'pay_url' => null,
            'amount' => $data['amount'] ?? $params['amount'] ?? null,
            'message' => $body['message'] ?? $body['msg'] ?? null,
            'raw' => $body,
        ];
    }

    protected function mapQuery(array $body, array $params): array
    {
        $data = $body['data'] ?? $body;
        $status = $this->resolveStatus($data);

        return [
            'success' => $this->isOk($body),
            'status' => $status,
            'order_no' => $params['order_no'] ?? ($data['order_no'] ?? ''),
            'third_order_no' => $data['third_order_no'] ?? $data['order_no'] ?? null,
            'pay_url' => null,
            'amount' => $data['amount'] ?? $data['actual_amount'] ?? null,
            'message' => $body['message'] ?? $body['msg'] ?? null,
            'raw' => $body,
        ];
    }

    protected function mapBalance(array $body, array $params): array
    {
        $data = $body['data'] ?? $body;

        return [
            'success' => $this->isOk($body),
            'status' => 'paid',
            'order_no' => '',
            'third_order_no' => null,
            'pay_url' => null,
            'amount' => $data['balance'] ?? null,
            'message' => $body['message'] ?? $body['msg'] ?? null,
            'raw' => $body,
        ];
    }

    protected function isOk(array $body): bool
    {
        $code = $body['code'] ?? $body['status'] ?? null;

        if (is_numeric($code)) {
            return (int) $code === 0 || (int) $code === 200;
        }

        if (is_string($code)) {
            return in_array(strtolower($code), ['ok', 'success', 'paid', 'completed'], true);
        }

        return isset($body['success']) ? (bool) $body['success'] : true;
    }

    protected function failed(string $message, array $extra = []): array
    {
        return array_merge([
            'success' => false,
            'status' => 'failed',
            'order_no' => '',
            'third_order_no' => null,
            'pay_url' => null,
            'amount' => null,
            'message' => $message,
            'raw' => [],
        ], $extra);
    }

    /**
     * 替换路径里的 {param} 占位
     */
    protected function renderPath(string $path, array $params): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($params) {
            return rawurlencode((string) ($params[$m[1]] ?? ''));
        }, $path);
    }
}
