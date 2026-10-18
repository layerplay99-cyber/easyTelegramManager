<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Permissions\Support\DataScope;
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

        // 上游配置是按 creator_id 隔离的：这里原来只按主键查，
        // 任意登录用户传别人的 id 就能读到（并改写）其接口映射。
        if (! app(DataScope::class)->isVisible($config)) {
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

        // 同上：不可见的上游一律按「不存在」处理，不暴露 id 是否存在
        if (! app(DataScope::class)->isVisible($config)) {
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
        $creatorId = $this->getLoginUserId();

        DB::transaction(function () use ($thirdConfigId, $submitted, $creatorId) {
            // 先物理清空该上游的全部旧映射（含软删除残留），再按本次提交重建。
            //
            // 用 withTrashed()->forceDelete() 而不是 delete()，有两个原因：
            //   1) 本表 (third_config_id, endpoint_code) 有唯一索引，而模型带软删除 ——
            //      软删除的行仍然占着这个唯一键，「清空某接口后再配置它」时
            //      updateOrCreate 查不到（被作用域排除）却插不进（唯一键冲突），
            //      整批保存直接失败。
            //   2) 本接口是「整批替换」语义，旧映射没有保留价值。
            ThirdConfigEndpoint::query()
                ->withTrashed()
                ->where('third_config_id', $thirdConfigId)
                ->forceDelete();

            foreach ($submitted as $item) {
                $code = trim((string) $item['code']);
                if ($code === '') {
                    continue;
                }

                $path = trim((string) ($item['path'] ?? ''));
                $method = trim((string) ($item['method'] ?? ''));
                $remark = trim((string) ($item['remark'] ?? ''));
                $enabled = array_key_exists('enabled', $item) ? (bool) $item['enabled'] : true;

                // 没有任何实质内容（既无路径也无方法，且未禁用）→ 不建映射，回退平台默认
                if ($path === '' && $method === '' && $remark === '' && $enabled) {
                    continue;
                }

                ThirdConfigEndpoint::query()->create([
                    'third_config_id' => $thirdConfigId,
                    'endpoint_code' => $code,
                    'path' => $path !== '' ? $path : null,
                    'method' => $method !== '' ? strtoupper($method) : null,
                    'enabled' => $enabled,
                    'remark' => $remark !== '' ? $remark : null,
                    'creator_id' => $creatorId,
                ]);
            }
        });

        return response()->json(['message' => '保存成功']);
    }
}
