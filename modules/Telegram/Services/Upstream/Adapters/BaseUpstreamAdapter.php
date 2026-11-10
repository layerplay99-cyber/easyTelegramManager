<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream\Adapters;

use Modules\Telegram\Contracts\UpstreamAdapter;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Upstream\UpstreamRequest;
use Modules\Telegram\Services\Upstream\UpstreamResponse;
use Modules\Telegram\Services\Upstream\UpstreamResult;

/**
 * 适配器基类：把「流程编排」统一兜住，插件只声明差异
 *
 * 一条链路分四层，插件只填后三层的「内容」，不写接口分派判断：
 *
 *   传输层   parseResponse()（基类，所有上游一样）
 *            请求没发出 / HTTP 非 2xx / 200 非 JSON / 200 空 body
 *   公共请求 commonRequest()（所有接口都要做的改写：固定头、时间戳、appKey…）
 *   接口层   requestHandlers() / responseHandlers() 两张声明表
 *            endpointCode => 方法名；新增接口只加一行 + 一个方法，不写 if
 *   公共业务 businessFailure()（所有接口共用的成功判定，如 HTTP200 + code!=0）
 *
 * 声明表里没登记的接口自动原样透传，所以插件可以只接自己有差异的接口，
 * 不影响这条上游上的其它功能；N 个接口就是 N 行声明 + N 个方法，
 * 分派逻辑全项目只有本类这一份。
 *
 * 想完全掌控流程也行：直接覆写 prepareRequest() / normalize()，
 * 或直接 implements UpstreamAdapter（Demo/DemoMinimalAdapter.php）。
 */
abstract class BaseUpstreamAdapter implements UpstreamAdapter
{
    /**
     * 请求侧分派表：平台接口 code => 本类里处理该接口请求的方法名。
     * 没登记的接口按平台标准参数原样发出。
     *
     * 登记的方法必须是 protected 或 public（基类负责调用）；
     * 写成 private 会判为不可调用，记日志后安全透传。
     *
     * @return array<string, string>
     */
    protected function requestHandlers(): array
    {
        return [];
    }

    /**
     * 响应侧分派表：平台接口 code => 本类里归一该接口响应的方法名。
     * 没登记的接口原样透传（data === raw，不附 _raw）。
     *
     * @return array<string, string>
     */
    protected function responseHandlers(): array
    {
        return [];
    }

    /**
     * 所有接口共用的请求改写；默认不改。
     * 单个接口的差异放 requestHandlers() 对应的方法里。
     */
    protected function commonRequest(UpstreamRequest $request, ThirdApiConfig $upstream): UpstreamRequest
    {
        return $request;
    }

    /**
     * 所有接口共用的业务失败判定；默认不判。
     *
     * 返回 null = 判定通过，继续做接口归一；
     * 返回 UpstreamResult = 直接作为失败结果返回。
     */
    protected function businessFailure(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): ?UpstreamResult {
        return null;
    }

    public function prepareRequest(UpstreamRequest $request, ThirdApiConfig $upstream): UpstreamRequest
    {
        $request = $this->commonRequest($request, $upstream);
        $handler = $this->handlerFor($this->requestHandlers(), $request->endpointCode, 'prepareRequest');

        if ($handler === null) {
            return $request;
        }

        $result = $this->{$handler}($request, $upstream);

        // handler 可以直接改可变对象（返回 void），也可以返回新对象
        return $result instanceof UpstreamRequest ? $result : $request;
    }

    /**
     * 查声明表并校验方法存在：拼错方法名时记日志 + 安全透传，不让插件静默失效
     */
    private function handlerFor(array $handlers, string $endpointCode, string $hook): ?string
    {
        $handler = $handlers[$endpointCode] ?? null;

        if ($handler === null) {
            return null;
        }

        if (! is_callable([$this, $handler])) {
            logger()->warning('[上游适配器] 分派表里的方法不存在，已透传', [
                'adapter' => static::key(),
                'hook' => $hook,
                'endpoint' => $endpointCode,
                'handler' => $handler,
            ]);

            return null;
        }

        return $handler;
    }

    public function parseResponse(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult {
        if (! $response->isTransportOk()) {
            return UpstreamResult::fail($response->transportError);
        }

        if (! $response->isHttpOk()) {
            return UpstreamResult::fail(
                '上游返回 HTTP ' . $response->httpStatus . '：' . $this->excerpt($response->body),
                ['_raw' => $response->body],
                httpStatus: $response->httpStatus
            );
        }

        // 200 + 空响应体：历史上按成功返回 ['_raw' => '']，这里保持一致，
        // 否则老上游的空成功响应会被插件机制误判成失败。
        if (! $response->isJson() && trim($response->body) === '') {
            return UpstreamResult::ok(
                ['_raw' => $response->body],
                ['_raw' => $response->body],
                httpStatus: $response->httpStatus
            );
        }

        if (! $response->isJson()) {
            return UpstreamResult::fail(
                '上游返回不是 JSON：' . $this->excerpt($response->body),
                ['_raw' => $response->body],
                httpStatus: $response->httpStatus
            );
        }

        return $this->normalize($response, $request, $upstream);
    }

    /**
     * 协议层归一：公共业务判定 → 查声明表分派 → 未登记的接口原样透传
     *
     * 子类一般不用覆写本方法，只覆写 businessFailure()
     * 和 responseHandlers() 里登记的具体方法；想完全自定义流程才覆写这里。
     */
    protected function normalize(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult {
        $failed = $this->businessFailure($response, $request, $upstream);

        if ($failed !== null) {
            return $failed;
        }

        $handler = $this->handlerFor($this->responseHandlers(), $request->endpointCode, 'parseResponse');
        $raw = $response->payload();

        if ($handler === null) {
            return UpstreamResult::ok($raw, $raw, $this->businessCode($response), $response->httpStatus);
        }

        return $this->{$handler}($response, $request, $upstream);
    }

    /**
     * 从多个候选路径里取第一个非空值 —— 兼容字段别名时最常用
     *
     * 例：$this->firstOf($response, ['data.available_funds', 'data.balance', 'balance'])
     */
    protected function firstOf(UpstreamResponse $response, array $paths, mixed $default = null): mixed
    {
        foreach ($paths as $path) {
            $value = $response->get($path);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /**
     * 解包：很多厂商把业务数据包在 data / result / result.data 里
     *
     * @return array<string, mixed>
     */
    protected function unwrap(UpstreamResponse $response, array $paths = ['data', 'result.data', 'result']): array
    {
        foreach ($paths as $path) {
            $value = $response->get($path);

            if (is_array($value)) {
                return $value;
            }
        }

        return $response->payload();
    }

    /**
     * 业务成功码判定：HTTP 200 但 body.code != 0 是支付/商户接口的常态
     *
     * @param array<int, int|string> $okCodes 哪些码算成功
     */
    protected function businessOk(
        UpstreamResponse $response,
        array $codePaths = ['code', 'errcode', 'status', 'ret'],
        array $okCodes = [0, 200, '0', '200', 'SUCCESS', 'OK', 'success', 'ok']
    ): bool {
        foreach ($codePaths as $path) {
            $code = $response->get($path);

            if ($code === null) {
                continue;
            }

            return in_array($code, $okCodes, true)
                || (! is_numeric($code) && in_array(strtoupper((string) $code), ['SUCCESS', 'OK'], true));
        }

        // 没有业务码字段：再看 success 布尔，都没有就认为成功（兼容老上游）
        $flag = $response->get('success');

        return $flag === null ? true : (bool) $flag;
    }

    protected function businessCode(UpstreamResponse $response): int|string|null
    {
        foreach (['code', 'errcode', 'ret', 'status'] as $path) {
            $code = $response->get($path);

            if ($code !== null && (is_int($code) || is_string($code))) {
                return $code;
            }
        }

        return null;
    }

    protected function businessMessage(UpstreamResponse $response, string $fallback = '上游业务处理失败'): string
    {
        foreach (['message', 'msg', 'error_msg', 'err_msg', 'error.description'] as $path) {
            $value = $response->get($path);

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return $fallback;
    }

    /**
     * 金额单位换算：分 → 元。上游字段是字符串时也能处理
     */
    protected function fenToYuan(mixed $fen): ?string
    {
        if (! is_numeric($fen)) {
            return null;
        }

        return number_format(((float) $fen) / 100, 2, '.', '');
    }

    protected function excerpt(string $text, int $limit = 200): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_substr($text, 0, $limit);
    }
}
