<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Security\UpstreamKeyService;
use Modules\Telegram\Services\Security\UpstreamSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * 三方 → 平台 的入站验签
 *
 * 与 ValidateApiKey（EXTERNAL_API_KEY，前端 / 内部采集器链路）并存，
 * 不替换、不影响它的任何现有挂载；群发入口 api/bot/send/group 也不经过这里。
 *
 * 校验顺序刻意固定：先验头部与时间窗（不碰密钥）→ 定位上游 → 解密
 * → 比对签名 → 最后才消耗 nonce，避免未验签请求把 nonce 占满造成 DoS。
 */
class ValidateUpstreamSignature
{
    public function handle(Request $request, Closure $next)
    {
        $accessKey = (string) $request->header(UpstreamSignature::HEADER_ACCESS_KEY, '');
        $timestamp = (string) $request->header(UpstreamSignature::HEADER_TIMESTAMP, '');
        $nonce = (string) $request->header(UpstreamSignature::HEADER_NONCE, '');
        $signature = (string) $request->header(UpstreamSignature::HEADER_SIGNATURE, '');

        if ($accessKey === ''
            || ! UpstreamSignature::validTimestamp($timestamp)
            || ! UpstreamSignature::validNonce($nonce)
            || ! UpstreamSignature::validSignatureFormat($signature)) {
            return $this->unauthorized('invalid headers');
        }

        $config = ThirdApiConfig::query()
            ->where('access_key', $accessKey)
            ->where('key_status', UpstreamKeyService::STATUS_ACTIVE)
            ->first();

        if (! $config) {
            return $this->unauthorized('unknown or revoked access key');
        }

        try {
            $secret = app(UpstreamKeyService::class)->secret($config);
        } catch (\Throwable $e) {
            Log::critical('[上游验签] 密钥不可用', [
                'upstream_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            return $this->unauthorized('key unavailable');
        }

        $expected = UpstreamSignature::sign(
            UpstreamSignature::canonical($request, $timestamp, $nonce),
            $secret
        );

        if (! hash_equals($expected, $signature)) {
            return $this->unauthorized('invalid signature');
        }

        // 验签通过后才占用 nonce：Cache::add 在 redis 下是原子 SETNX
        $nonceKey = 'upstream:nonce:' . $config->id . ':' . $nonce;

        if (! Cache::add($nonceKey, 1, UpstreamSignature::NONCE_TTL)) {
            return $this->unauthorized('replayed request');
        }

        $request->attributes->set('upstream_config', $config);
        $request->attributes->set('upstream_tenant_id', (int) ($config->tenant_id ?? 0));

        return $next($request);
    }

    /**
     * 绝不回显期望签名、canonical、body 或密钥相关内容
     */
    private function unauthorized(string $reason): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'message' => 'Unauthorized (' . $reason . ')',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
