<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController;
use Catch\Enums\Code;
use Catch\Support\ResponseBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;

/**
 * 模块控制器基类
 *
 * Catch 的 CatchController 只提供「登录用户」相关方法，没有统一响应封装，
 * 钱包相关控制器直接调 $this->success()/failed() 会报 method does not exist。
 * 这里补上：
 *   - 普通响应：{code: 10000, message: ..., data: ...}
 *   - 分页响应：{code: 10000, message: ..., data: [...], total, limit, page}
 *
 * 前端 useGetList 取 r.data.data 渲染表格、r.data.total 做分页，
 * useCreate/useUpdate 判断 r.data.code === Code.SUCCESS，结构必须一致。
 */
abstract class BaseController extends CatchController
{
    /**
     * 成功响应
     */
    protected function success(mixed $data = [], string $message = ''): ResponseBuilder
    {
        $builder = ($data instanceof LengthAwarePaginator || $data instanceof Paginator)
            ? ResponseBuilder::paginate($data)
            : ResponseBuilder::success($data);

        return $message === '' ? $builder : $builder->message($message);
    }

    /**
     * 失败响应（HTTP 状态保持 200，靠 code 区分，方便前端统一取 message）
     */
    protected function failed(string $message = '', mixed $data = []): ResponseBuilder
    {
        $builder = ResponseBuilder::fail($data);

        return $message === '' ? $builder : $builder->message($message);
    }

    /**
     * 成功响应（非后台来源：MiniApp / 外部调用）
     *
     * ResponseBuilder 只有在请求带 Request-from: dashboard 时才会被监听器展开，
     * 外部调用没有这个头，会拿到一个空 JSON。这里直接输出完整结构。
     */
    protected function jsonSuccess(mixed $data = [], string $message = ''): JsonResponse
    {
        return response()->json([
            'code' => Code::SUCCESS->value(),
            'message' => $message === '' ? Code::SUCCESS->message() : $message,
            'data' => $data,
        ]);
    }

    /**
     * 失败响应（非后台来源）
     */
    protected function jsonError(string $message = '', mixed $data = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'code' => Code::FAILED->value(),
            'message' => $message === '' ? Code::FAILED->message() : $message,
            'data' => $data,
        ], $status);
    }
}
