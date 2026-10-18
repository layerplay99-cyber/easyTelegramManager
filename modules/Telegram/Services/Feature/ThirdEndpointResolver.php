<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Models\ThirdApiEndpoints;
use Modules\Telegram\Models\ThirdConfigEndpoint;

/**
 * 解析「某上游 + 某平台接口」最终生效的请求要素
 *
 * 逐项覆盖：上游专属配置（third_config_endpoints）优先，留空的项回退平台默认值。
 *
 * 例：同一个平台接口 merchant.balance（获取余额）
 *   上游1 配了 path=api/webhook/getBalance → https://www.example.com/api/webhook/getBalance
 *   上游2 配了 path=api/webhook/getAmount  → https://www.example2.com/api/webhook/getAmount
 *   上游3 没配                              → 回退平台默认 {base_url}/api/merchant/balance
 *
 * 因此功能代码只需引用平台 code，路径差异全部在后台配置，无需改代码。
 */
class ThirdEndpointResolver
{
    /**
     * @return array{
     *     enabled: bool,
     *     configured: bool,
     *     path: string,
     *     method: string,
     *     headers: array<string, mixed>,
     *     query: array<string, mixed>,
     *     timeout: int
     * }
     */
    public static function resolve(ThirdApiConfig $third, ThirdApiEndpoints $endpoint): array
    {
        $override = ThirdConfigEndpoint::query()
            ->where('third_config_id', $third->id)
            ->where('endpoint_code', $endpoint->code)
            ->first();

        return [
            // 该上游是否支持此接口（未配置任何映射时视为支持）
            'enabled' => $override ? (bool) $override->enabled : true,
            // 该上游是否单独配置过（便于后台展示与排查）
            'configured' => $override !== null,
            'path' => self::pickString($override?->path, (string) $endpoint->path_template),
            'method' => strtoupper(self::pickString($override?->method, (string) $endpoint->method) ?: 'GET'),
            'headers' => self::pickArray($override?->headers, $endpoint->headers),
            'query' => self::pickArray($override?->query, $endpoint->query),
            'timeout' => (int) self::normalizeTimeout($endpoint->timeout),
        ];
    }

    /**
     * 未显式配置时的默认总超时（秒）
     */
    public static function defaultTimeout(): int
    {
        return (int) config('telegram.http_client.timeout', 10);
    }

    /**
     * 总超时的硬上限（秒）
     *
     * 后台「上游接口配置」里把超时调得再大也不会超过它。单个上游卡顿最多只占住
     * worker 这么久，剩下的靠「按 order_no 查单对账」兜底，而不是让队列干等 30 秒。
     */
    public static function maxTimeout(): int
    {
        return (int) config('telegram.http_client.max_timeout', 15);
    }

    /**
     * 归一化超时：空值走默认值，再统一封顶
     *
     * @param mixed $timeout 平台接口表里配的秒数
     */
    public static function normalizeTimeout(mixed $timeout): int
    {
        $timeout = (int) ($timeout ?: self::defaultTimeout());

        return max(1, min($timeout, self::maxTimeout()));
    }

    /**
     * 拼最终 URL
     *
     * resolved path 支持绝对地址（以 http:// 或 https:// 开头），
     * 用于那种连域名都与 base_url 不同的上游。
     */
    public static function buildUrl(ThirdApiConfig $third, string $path): string
    {
        $path = trim($path);

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return rtrim((string) $third->api_url, '/') . '/' . ltrim($path, '/');
    }

    private static function pickString(?string $override, string $fallback): string
    {
        $value = trim((string) $override);

        return $value !== '' ? $value : $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private static function pickArray(mixed $override, mixed $fallback): array
    {
        if (is_array($override) && $override !== []) {
            return $override;
        }

        return is_array($fallback) ? $fallback : [];
    }
}
