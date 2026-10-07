<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Telegram\Models\ThirdApiEndpoints;

/**
 * 三方 API 接口管理
 *
 * 接口从 thirdapi_config 拆出来独立成表，可被多个功能复用：
 * 功能只引用 endpoint_id，不必为每个接口重复建上游配置，也不必在功能里硬编码路径。
 */
class ThirdApiEndpointsController extends Controller
{
    public function __construct(protected readonly ThirdApiEndpoints $model)
    {
    }

    public function index(Request $request): mixed
    {
        return $this->model->setBeforeGetList(function ($query) use ($request) {
            if ($thirdConfigId = $request->input('third_config_id')) {
                $query->where('third_config_id', $thirdConfigId);
            }

            return $query;
        })->getList();
    }

    public function store(Request $request): mixed
    {
        return $this->model->storeBy($this->normalize($request->all()));
    }

    public function show(int|string $id): mixed
    {
        return $this->model->firstBy($id);
    }

    public function update(int|string $id, Request $request): mixed
    {
        return $this->model->updateBy($id, $this->normalize($request->all()));
    }

    public function destroy(int|string $id): mixed
    {
        return $this->model->deleteBy($id);
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