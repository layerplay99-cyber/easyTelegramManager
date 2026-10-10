<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

/**
 * 回调/请求的签名与验签
 *
 * 旧实现「知道密钥才能伪造」的说法没错，但只解决了伪造、没解决重放和串群：
 *   - 同一条合法回调被重放 N 次 → 群里刷 N 条（靠 dedup_key 拦）
 *   - 只在 .env 里配一把全局密钥 → 所有上游共用，泄露即全崩，也无法按上游吊销
 *
 * 现在的做法：
 *   - 密钥按 hook 单独配（feature_hooks.secret），留空才回落全局 TELEGRAM_HOOK_SECRET
 *   - 签名覆盖全部业务字段，改任何一个字段都验不过
 *   - 可选 timestamp + nonce 防重放（窗口内 nonce 不重复）
 */
class CallbackSigner
{
    /**
     * 待签串：除 sign 外，非空参数按 key 升序 k=v 用 & 连接
     *
     * @param array<string, mixed> $params
     */
    public static function buildString(array $params, array $ignore = ['sign']): string
    {
        $params = array_filter(
            $params,
            fn ($v, $k) => ! in_array((string) $k, $ignore, true) && $v !== null && $v !== '',
            ARRAY_FILTER_USE_BOTH
        );

        ksort($params);

        return collect($params)
            ->map(fn ($v, $k) => $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            ->implode('&');
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function sign(array $params, string $secret, string $algo = 'hmac_sha256'): string
    {
        $string = self::buildString($params);

        if ($secret === '') {
            return '';
        }

        return strtolower($algo) === 'md5'
            ? strtoupper(md5($string . '&key=' . $secret))
            : hash_hmac('sha256', $string, $secret);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function verify(array $params, string $secret, string $algo = 'hmac_sha256', ?string $sign = null): bool
    {
        $sign = (string) ($sign ?? $params['sign'] ?? '');

        if ($sign === '' || $secret === '') {
            return false;
        }

        return hash_equals(self::sign($params, $secret, $algo), $sign);
    }

    /**
     * 时间戳是否在允许窗口内（防重放）
     */
    public static function timestampFresh(mixed $timestamp, int $ttl = 300): bool
    {
        $ts = is_numeric($timestamp) ? (int) $timestamp : 0;

        if ($ts <= 0) {
            return false;
        }

        // 兼容秒级与毫秒级
        if ($ts > 100000000000) {
            $ts = (int) floor($ts / 1000);
        }

        return abs(time() - $ts) <= $ttl;
    }

    /**
     * nonce 在窗口内是否用过（用过即重放）
     */
    public static function nonceSeen(string $scope, string $nonce, int $ttl = 600): bool
    {
        if ($nonce === '') {
            return false;
        }

        $key = 'hook:nonce:' . $scope . ':' . md5($nonce);

        if (\Illuminate\Support\Facades\Cache::has($key)) {
            return true;
        }

        \Illuminate\Support\Facades\Cache::put($key, true, $ttl);

        return false;
    }
}
