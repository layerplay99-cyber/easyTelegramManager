<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Demo;

/**
 * 交易加签器（Demo）：出站方向
 *
 * 「我方 → 上游」提交交易结果时，把要发的字段算个签名一起发过去（sign()），
 * 上游按同样规则验签。出站用上游给的 secrept_key，规则由上游定。
 *
 * ⚠️ 签名的关键是「两边算出来的待签串必须一模一样」，最容易踩的坑有三个：
 *      1) 是否排序            —— 这里统一 ksort 升序
 *      2) 空值要不要参与      —— 这里统一丢弃 null / '' / []
 *      3) 数组/布尔怎么字符串化 —— 这里统一 json，避免 PHP 的 "Array" / "1"
 *
 * 如果你的上游用的是别的规则（md5、末尾拼 &key=xxx、只签部分字段），
 * 只改 buildString() 和 sign() 两个方法，业务代码一行都不用动。
 *
 * 入站验签已统一走平台头签名规范 UpstreamSignature（access_key/secret_key），
 * 不再用 body-sign；这个文件只保留出站加签。
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
