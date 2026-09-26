<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = $request->header('Api-Key') ?? $request->get('api_key');
        $signature = $request->header('Api-Signature');

        $secret = (string) config('services.external.api_key');

        // 密钥未配置时直接拒绝，避免 hash_equals(null) 抛 TypeError 造成 500
        if ($secret === '') {
            return response()->json([
                'message' => 'Unauthorized (api key not configured)'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // hash_equals 要求两个参数都是字符串，先做类型转换
        if (! is_string($apiKey) || ! hash_equals($secret, $apiKey)) {
            return response()->json([
                'message' => 'Unauthorized (invalid api key)'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        // 注意：绝不能把 $expectedSignature 或 $payload 回显到响应里。
        // 原来这里把「正确签名」直接返回给了调用方，等于把校验形同虚设，
        // 任何人拿一次报错响应就能伪造任意请求的签名。
        if (! is_string($signature) || ! hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'message' => 'Unauthorized (invalid signature)'
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
