<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Security;

use Illuminate\Http\Request;

/**
 * 三方 → 平台 的入站签名规范
 *
 * 请求头：X-Access-Key / X-Timestamp / X-Nonce / X-Signature
 * 待签串（\n 分隔，末尾无换行）：
 *   METHOD
 *   PATH?SORTED_QUERY
 *   TIMESTAMP
 *   NONCE
 *   BODY_SHA256
 * 签名：hash_hmac('sha256', canonical, secret_raw)，小写 hex。
 *
 * 这里只做「拼串 + 算签名」，时间戳 / nonce / 解密 / 比对由中间件负责。
 */
class UpstreamSignature
{
    public const HEADER_ACCESS_KEY = 'X-Access-Key';
    public const HEADER_TIMESTAMP = 'X-Timestamp';
    public const HEADER_NONCE = 'X-Nonce';
    public const HEADER_SIGNATURE = 'X-Signature';

    public const TIMESTAMP_TTL = 300;
    public const NONCE_TTL = 600;
    public const NONCE_MAX_LENGTH = 64;

    public static function canonical(Request $request, string $timestamp, string $nonce): string
    {
        return implode("\n", [
            strtoupper($request->method()),
            self::pathWithQuery($request),
            $timestamp,
            $nonce,
            hash('sha256', (string) $request->getContent()),
        ]);
    }

    public static function sign(string $canonical, string $secret): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }

    public static function validNonce(string $nonce): bool
    {
        return $nonce !== ''
            && strlen($nonce) <= self::NONCE_MAX_LENGTH
            && preg_match('/^[A-Za-z0-9_-]+$/', $nonce) === 1;
    }

    public static function validTimestamp(string $timestamp): bool
    {
        if (preg_match('/^[0-9]{1,11}$/', $timestamp) !== 1) {
            return false;
        }

        $ts = (int) $timestamp;

        return $ts > 0 && abs(time() - $ts) <= self::TIMESTAMP_TTL;
    }

    public static function validSignatureFormat(string $signature): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $signature) === 1;
    }

    /**
     * PATH 取 getPathInfo()（不含 host）；query 按 key 升序、键值 rawurlencode 后拼接。
     */
    private static function pathWithQuery(Request $request): string
    {
        $path = $request->getPathInfo();
        $query = $request->query();

        if (empty($query)) {
            return $path;
        }

        ksort($query);

        $parts = [];

        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return $path . '?' . implode('&', $parts);
    }
}
