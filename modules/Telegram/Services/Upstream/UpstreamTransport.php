<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream;

use Modules\Telegram\Contracts\UpstreamAdapter;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Upstream\Adapters\DefaultAdapter;

/**
 * 出站调用的「适配器安全壳」
 *
 * 各出站点（BaseCustomFeature / UpstreamCaller / HttpRequestDriver …）
 * 发请求前后调用这里，而不是直接调适配器。原因有二：
 *
 * 1. 插件是业务代码，可能抛异常。契约要求「插件坏了不能让业务链路挂掉」，
 *    这里统一兜底：任何异常都记日志 + 回退到直通基线，请求照发、响应照回。
 * 2. 收敛重复代码，以后加签名/审计/试调日志只需要改这一处。
 *
 * HTTP 本身仍由各调用点发（它们的 URL 拼装、路径模板、token 注入各不相同），
 * 本类只负责「请求描述 → 插件改写」和「原始响应 → 标准结果」两段。
 */
class UpstreamTransport
{
    public function __construct(protected UpstreamAdapterRegistry $registry)
    {
    }

    /**
     * 按上游实例取适配器（adapter 留空 / 类被删 → 直通）
     */
    public function adapterFor(ThirdApiConfig $upstream): UpstreamAdapter
    {
        return $this->registry->forUpstream($upstream);
    }

    /**
     * 请求前钩子：标准参数 → 该厂商参数
     */
    public function prepare(
        UpstreamAdapter $adapter,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamRequest {
        // UpstreamRequest 是可变对象：插件可能在改到一半时才抛异常，
        // 此时直接「回退直通」会带着半套厂商参数发出去，比不回退更危险。
        // 先留一份原始快照（数组是值语义，clone 足够），异常时从快照重来。
        $original = clone $request;

        try {
            $result = $adapter->prepareRequest($request, $upstream);

            return $result instanceof UpstreamRequest ? $result : $original;
        } catch (\Throwable $e) {
            $this->warn('prepareRequest', $adapter, $e);

            return (new DefaultAdapter())->prepareRequest($original, $upstream);
        }
    }

    /**
     * 响应后钩子：厂商响应 → 平台标准结果
     */
    public function parse(
        UpstreamAdapter $adapter,
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult {
        try {
            return $adapter->parseResponse($response, $request, $upstream);
        } catch (\Throwable $e) {
            $this->warn('parseResponse', $adapter, $e);

            return (new DefaultAdapter())->parseResponse($response, $request, $upstream);
        }
    }

    /**
     * 功能自带解构：功能（FeatureDriver）声明了 responseHandlers() 的接口，
     * 优先用功能自己的解构逻辑，而不是上游适配器的。
     *
     * 传输层判定（请求没发出 / 非 2xx / 非 JSON）与 BaseUpstreamAdapter::parseResponse
     * 完全一致——这些与厂商格式无关，功能侧不必重复判断，mapper 只负责
     * 「JSON body → 平台标准结构」。
     *
     * 安全壳约定不变：mapper 抛异常 / 返回非法值时回退到上游适配器解构，
     * 业务链路不会因为功能代码坏掉而挂掉。
     */
    public function parseWithFeatureMapper(
        callable $mapper,
        UpstreamAdapter $adapter,
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

        // 200 + 空响应体：历史行为按成功返回 ['_raw' => '']，与适配器基线保持一致
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

        try {
            $result = $mapper($response, $request, $upstream);
        } catch (\Throwable $e) {
            logger()->warning('[上游适配器] 功能自带解构异常，已回退适配器', [
                'endpoint' => $request->endpointCode,
                'error' => $e->getMessage(),
            ]);

            return $this->parse($adapter, $response, $request, $upstream);
        }

        $result = $this->featureResult($result, $response);

        if ($result === null) {
            logger()->warning('[上游适配器] 功能自带解构返回值非法，已回退适配器', [
                'endpoint' => $request->endpointCode,
            ]);

            return $this->parse($adapter, $response, $request, $upstream);
        }

        return $result;
    }

    /**
     * 功能 mapper 的返回值 → UpstreamResult
     *
     * 支持：
     *   - UpstreamResult：原样使用
     *   - array 且带 _error 键：失败（与 callEndpoint 的错误约定一致）
     *   - array：成功，data 即返回值，raw 保留上游原文
     */
    private function featureResult(mixed $result, UpstreamResponse $response): ?UpstreamResult
    {
        if ($result instanceof UpstreamResult) {
            return $result;
        }

        if (! is_array($result)) {
            return null;
        }

        if (array_key_exists('_error', $result)) {
            $message = (string) $result['_error'];

            return UpstreamResult::fail(
                $message !== '' ? $message : '上游业务处理失败',
                $response->payload(),
                httpStatus: $response->httpStatus
            );
        }

        return UpstreamResult::ok($result, $response->payload(), httpStatus: $response->httpStatus);
    }

    /**
     * 请求根本没发出去时也走一遍 parseResponse，
     * 让插件有机会把异常翻译成业务错误文案。
     */
    public function transportFailed(
        UpstreamAdapter $adapter,
        string $error,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult {
        return $this->parse(
            $adapter,
            UpstreamResponse::transportFailed($error),
            $request,
            $upstream
        );
    }

    private function excerpt(string $text, int $limit = 200): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, $limit);
    }

    private function warn(string $hook, UpstreamAdapter $adapter, \Throwable $e): void
    {
        logger()->warning('[上游适配器] 插件异常，已回退直通', [
            'adapter' => $adapter::key(),
            'hook' => $hook,
            'error' => $e->getMessage(),
        ]);
    }
}
