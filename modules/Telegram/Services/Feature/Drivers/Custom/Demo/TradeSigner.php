<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Demo;

use Illuminate\Support\Facades\Cache;

/**
 * 交易签名器（Demo）
 *
 * 「我和上游约定一把密钥」这件事落到代码上就是这一个文件：
 *   sign()   出站：把要发给上游的字段算个签名一起发过去
 *   verify() 入站：把上游发来的字段按同样的规则算一遍，比对签名
 *
 * ⚠️ 签名的关键是「两边算出来的待签串必须一模一样」，
 *    最容易踩的坑有三个，这里都显式处理了：
 *      1) 是否排序            —— 这里统一 ksort 升序
 *      2) 空值要不要参与      —— 这里统一丢弃 null / '' / []
 *      3) 数组/布尔怎么字符串化 —— 这里统一 json，避免 PHP 的 "Array" / "1"
 *
 * 如果你的上游用的是别的规则（比如 md5、末尾拼 &key=xxx、只签部分字段），
 * 只需要改 buildString() 和 sign() 两个方法，业务代码一行都不用动。
 *
 * 平台通用的那套在 Modules\Telegram\Services\Feature\CallbackSigner，
 * 规则一致；这个文件留在这里是为了让你看到「换算法要改哪里」。
 */
class TradeSigner
{
    /**
     * 待签串：除 sign 外的非空字段，按 key 升序，k=v 用 & 连接
     *
     * 例：amount=199.00&merchant_id=M9001&nonce=ab12&timestamp=1791645000&trade_no=T10001
     *
     * @param array<string, mixed> $params
     */
    public static function buildString(array $params): string
    {
        $params = array_filter(
            $params,
            static fn ($v, $k) => $k !== 'sign' && $v !== null && $v !== '' && $v !== [],
            ARRAY_FILTER_USE_BOTH
        );

        ksort($params);

        return collect($params)
            ->map(static fn ($v, $k) => $k . '=' . self::stringify($v))
            ->implode('&');
    }

    /**
     * 签名（出站用）
     *
     * @param array<string, mixed> $params 业务字段（不要提前放 sign，这里会先剔除）
     */
    public static function sign(array $params, string $secret, string $algo = 'hmac_sha256'): string
    {
        if ($secret === '') {
            return '';
        }

        $string = self::buildString($params);

        return $algo === 'md5'
            // 老上游常见：md5(待签串 + &key=密钥) 后转大写
            ? strtoupper(md5($string . '&key=' . $secret))
            // 推荐：HMAC-SHA256，转大写便于肉眼比对
            : strtoupper(hash_hmac('sha256', $string, $secret));
    }

    /**
     * 验签（入站用）
     */
    public static function verify(array $params, string $secret, ?string $sign = null, string $algo = 'hmac_sha256'): bool
    {
        $sign = strtoupper((string) ($sign ?? $params['sign'] ?? ''));

        if ($sign === '' || $secret === '') {
            return false;
        }

        // hash_equals 防时序攻击，别用 ==
        return hash_equals(self::sign($params, $secret, $algo), $sign);
    }

    /**
     * timestamp 是否在时间窗口内（防重放第一道）
     */
    public static function fresh(mixed $timestamp, int $ttl = 300): bool
    {
        $ts = is_numeric($timestamp) ? (int) $timestamp : 0;

        if ($ts <= 0) {
            return false;
        }

        // 兼容毫秒
        if ($ts > 100000000000) {
            $ts = (int) floor($ts / 1000);
        }

        return abs(time() - $ts) <= $ttl;
    }

    /**
     * nonce 是否用过（防重放第二道）：窗口内同一个 nonce 只放行一次
     */
    public static function nonceSeen(string $scope, string $nonce, int $ttl = 600): bool
    {
        if ($nonce === '') {
            return false;
        }

        $key = 'demo:trade:nonce:' . md5($scope . '|' . $nonce);

        if (Cache::has($key)) {
            return true;
        }

        Cache::put($key, true, $ttl);

        return false;
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
