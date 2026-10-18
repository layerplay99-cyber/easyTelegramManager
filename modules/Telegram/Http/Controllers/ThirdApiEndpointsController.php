<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Telegram\Models\ThirdApiEndpoints;

/**
 * 平台接口管理（只读清单 + 平台级默认值）
 *
 * 接口由功能代码声明（驱动实现 EndpointProvider），由 PlatformEndpointRegistry::sync()
 * 同步进库。因此：
 *
 *   · 代码声明的字段（code / name / method / path_template / params_schema /
 *     response_schema）—— 以代码为准，每次同步覆盖。后台改了也是白改，所以这里一律
 *     不允许改，只展示。
 *   · 平台级默认值（headers / query / timeout / enabled / remark）—— 不是代码声明的，
 *     同步不会覆盖（见 PlatformEndpointRegistry::sync 的注释），可以在这里改。
 *
 * 真正要「换个路径 / 换个方法」请到「上游接口配置」按上游覆盖，
 * 那里改的是 third_config_endpoints，不会影响平台默认。
 */
class ThirdApiEndpointsController extends Controller
{
    /**
     * 由代码声明、不允许后台修改的字段
     */
    protected const CODE_OWNED_FIELDS = [
        'code',
        'name',
        'method',
        'path_template',
        'params_schema',
        'response_schema',
    ];

    public function __construct(protected readonly ThirdApiEndpoints $model)
    {
    }

    public function index(Request $request): mixed
    {
        return $this->model->setBeforeGetList(function ($query) use ($request) {
            // 接口规范是平台统一的，不再按上游过滤（上游关联已改为 bots.third_config_id）
            if ($code = $request->input('code')) {
                $query->where('code', $code);
            }

            return $query;
        })->getList();
    }

    /**
     * 平台接口由功能代码声明并同步，不支持后台手动新增
     *
     * 手动新增的 code 不在任何驱动里，功能引用不到它，而且会一直残留成孤儿记录。
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'message' => '平台接口由功能代码声明，不支持手动新增。请在驱动里实现 EndpointProvider 后执行 php artisan telegram:sync-features',
        ], 422);
    }

    public function show(int|string $id): mixed
    {
        return $this->model->firstBy($id);
    }

    /**
     * 只更新平台级默认值，代码声明的字段一律忽略
     */
    public function update(int|string $id, Request $request): mixed
    {
        $data = $this->normalize($request->all());

        foreach (self::CODE_OWNED_FIELDS as $field) {
            unset($data[$field]);
        }

        return $this->model->updateBy($id, $data);
    }

    /**
     * 平台接口由同步维护，不支持后台删除（删了下一次同步又会回来）
     */
    public function destroy(int|string $id): JsonResponse
    {
        return response()->json([
            'message' => '平台接口由功能代码同步维护，不支持删除。停用请改为把「启用」关掉',
        ], 422);
    }

    /**
     * 归档成数组保存，避免前端传字符串导致 json 字段报错
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function normalize(array $data): array
    {
        foreach (['headers', 'query'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            if (is_string($data[$field])) {
                $decoded = json_decode($data[$field], true);
                $data[$field] = is_array($decoded) ? $decoded : null;
            }
        }

        return $data;
    }
}