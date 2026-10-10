<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Models\ThirdApiEndpoints;
use Modules\Telegram\Models\ThirdConfigEndpoint;

/**
 * 上游 × 平台接口 的路径映射管理
 *
 * 解决的业务问题：同一个业务语义（如「获取余额」）在不同上游的路径并不相同
 *   上游1 → /api/webhook/getBalance
 *   上游2 → /api/webhook/getAmount
 *
 * 后台在这里为每个上游单独配置，功能代码只引用平台 code，无需硬编码 URL。
 * 某上游某接口的 path 留空（或不配置）时，自动回退使用平台默认 path_template。
 */
class ThirdConfigEndpointsController extends Controller
{
    /**
     * 某上游下所有平台接口 + 该上游的专属配置
     */
    public function index(int $thirdConfigId): JsonResponse
    {
        $config = ThirdApiConfig::query()->find($thirdConfigId);

        if (! $config) {
            return response()->json(['message' => '上游配置不存在'], 404);
        }

        $overrides = ThirdConfigEndpoint::query()
            ->where('third_config_id', $thirdConfigId)
            ->get()
            ->keyBy('endpoint_code');

        $endpoints = ThirdApiEndpoints::query()
            ->orderBy('code')
            ->get()
            ->map(function (ThirdApiEndpoints $endpoint) use ($overrides) {
                $override = $overrides->get($endpoint->code);

                return [
                    'code' => $endpoint->code,
                    'name' => $endpoint->name,
                    // 平台默认（回退值）
                    'platform_path' => $endpoint->path_template,
                    'platform_method' => $endpoint->method,
                    // 上游专属配置（空串表示未配置 → 回退平台默认）
                    'path' => (string) ($override?->path ?? ''),
                    'method' => (string) ($override?->method ?? ''),
                    'enabled' => $override ? (bool) $override->enabled : true,
                    'configured' => $override !== null,
                    'remark' => (string) ($override?->remark ?? ''),
                ];
            });

        return response()->json([
            'third_config' => [
                'id' => $config->id,
                'name' => $config->name,
                'api_url' => $config->api_url,
            ],
            'endpoints' => $endpoints,
        ]);
    }

    /**
     * 批量保存某上游的接口映射
     *
     * 提交时不带某项、或该项全为空 → 删除映射，回退平台默认值。
     */
    public function save(int $thirdConfigId, Request $request): JsonResponse
    {
        $config = ThirdApiConfig::query()->find($thirdConfigId);

        if (! $config) {
            return response()->json(['message' => '上游配置不存在'], 404);
        }

        $validated = $request->validate([
            'endpoints' => 'nullable|array',
            'endpoints.*.code' => 'required|string|max:64',
            'endpoints.*.path' => 'nullable|string|max:500',
            'endpoints.*.method' => 'nullable|string|max:16',
            'endpoints.*.enabled' => 'nullable|boolean',
            'endpoints.*.remark' => 'nullable|string|max:255',
        ]);

        $submitted = collect($validated['endpoints'] ?? []);
        $codes = $submitted->pluck('code')->filter()->unique()->all();
        $creatorId = $this->getLoginUserId();

        DB::transaction(function () use ($thirdConfigId, $submitted, $codes, $creatorId) {
            foreach ($submitted as $item) {
                $code = trim((string) $item['code']);
                if ($code === '') {
                    continue;
                }

                $path = trim((string) ($item['path'] ?? ''));
                $method = trim((string) ($item['method'] ?? ''));
                $remark = trim((string) ($item['remark'] ?? ''));
                $enabled = array_key_exists('enabled', $item) ? (bool) $item['enabled'] : true;

                // 没有任何实质内容（既无路径也无方法，且未禁用）→ 删除映射，回退平台默认
                if ($path === '' && $method === '' && $remark === '' && $enabled) {
                    ThirdConfigEndpoint::query()
                        ->where('third_config_id', $thirdConfigId)
                        ->where('endpoint_code', $code)
                        ->delete();
                    continue;
                }

                ThirdConfigEndpoint::query()->updateOrCreate(
                    [
                        'third_config_id' => $thirdConfigId,
                        'endpoint_code' => $code,
                    ],
                    [
                        'path' => $path !== '' ? $path : null,
                        'method' => $method !== '' ? strtoupper($method) : null,
                        'enabled' => $enabled,
                        'remark' => $remark !== '' ? $remark : null,
                        'creator_id' => $creatorId,
                    ]
                );
            }

            // 本次未提交的旧映射一并清理，避免残留失效配置
            ThirdConfigEndpoint::query()
                ->where('third_config_id', $thirdConfigId)
                ->whereNotIn('endpoint_code', $codes)
                ->delete();
        });

        return response()->json(['message' => '保存成功']);
    }
}
