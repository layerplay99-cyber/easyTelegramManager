<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

/**
 * 回调/请求的出站加签
 *
 * 出站（我方 → 上游）请求用 sign() 把参数算个签名放 body 里，上游验签。
 * 密钥用上游给的 secrept_key，规则由上游定。
 *
 * 入站验签已统一走平台头签名规范 UpstreamSignature（access_key/secret_key），
 * 不再用 body-sign；本类的 verify / timestampFresh / nonceSeen 已移除。
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
}
