<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 三方开放接口的接入自检端点
 *
 * 挂 ValidateUpstreamSignature，三方拿到密钥后可先用它验连通性与签名实现；
 * 后续需要「加密处理」的三方接口统一放 api/upstream/v1 分组。
 */
class UpstreamOpenController extends Controller
{
    public function ping(Request $request): JsonResponse
    {
        $config = $request->attributes->get('upstream_config');

        return response()->json([
            'status' => 'ok',
            'access_key' => $config?->access_key,
            'tenant_id' => (int) ($config?->tenant_id ?? 0),
            'server_time' => time(),
        ]);
    }
}
