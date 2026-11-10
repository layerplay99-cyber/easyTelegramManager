<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Models\ThirdApiEndpoints;
use Modules\Telegram\Services\Upstream\UpstreamRequest as OutboundRequest;
use Modules\Telegram\Services\Upstream\UpstreamResponse as InboundResponse;
use Modules\Telegram\Services\Upstream\UpstreamTransport;

/**
 * 调上游接口（带签名）
 *
 * 供「按钮点击 → 提交上游」这类场景使用：
 *   · 路径/方法/头/固定参数仍走 ThirdEndpointResolver（上游可在后台按接口覆盖）
 *   · 业务参数由功能配置里的模板渲染（{{payload.trade_no}}、{{session.merchant_id}} 等）
 *   · 请求按上游的密钥签名，上游验签通过才执行
 *
 * @see ThirdEndpointResolver 逐项覆盖规则
 */
class UpstreamCaller
{
    public function __construct(protected TemplateRenderer $renderer)
    {
    }

    /**
     * @param array<string, mixed> $data    模板数据源（回调原文 + 会话信息）
     * @param array<string, mixed> $options params / method / sign / sign_algo / sign_field / timeout
     * @return array{success:bool,http_code:int,body:mixed,raw:string,url:string,error:string}
     */
    public function call(string $endpointCode, ThirdApiConfig $third, array $data, array $options = []): array
    {
        $endpoint = ThirdApiEndpoints::query()
            ->where('code', $endpointCode)
            ->where('enabled', true)
            ->first();

        if (! $endpoint) {
            return $this->failed("平台接口不存在：{$endpointCode}");
        }

        $resolved = ThirdEndpointResolver::resolve($third, $endpoint);

        if (! $resolved['enabled']) {
            return $this->failed('当前上游未启用该接口：' . $endpoint->code);
        }

        $params = $this->renderMap((array) ($options['params'] ?? []), $data);
        $params = array_merge($resolved['query'], $params);

        $method = strtoupper((string) ($options['method'] ?? $resolved['method'] ?: 'GET'));

        // 签名：把 timestamp/nonce 一起签进去，上游可据此防重放
        if ($options['sign'] ?? true) {
            $params['timestamp'] ??= (string) time();
            $params['nonce'] ??= Str::random(16);
            $params[$options['sign_field'] ?? 'sign'] = CallbackSigner::sign(
                $params,
                (string) $third->secrept_key,
                (string) ($options['sign_algo'] ?? 'hmac_sha256')
            );
        }

        $path = $this->renderString($resolved['path'], $data + $params);
        $url = ThirdEndpointResolver::buildUrl($third, $path);

        // ---- 上游协议适配 ----
        // 交互按钮的成功 / 失败会驱动会话状态机（finish / release 允许重试），
        // 所以这里的 success 必须走插件的业务码判定，不能只看 HTTP 200。
        $transport = app(UpstreamTransport::class);
        $adapter = $transport->adapterFor($third);

        $headers = (array) ($resolved['headers'] ?? []);
        if ($third->token) {
            $headers['Authorization'] = $third->token;
        }

        $outbound = new OutboundRequest(
            endpointCode: $endpoint->code,
            method: $method,
            path: $path,
            url: $url,
            params: $params,
            query: [],
            headers: $headers,
            pathParams: [],
            timeout: ThirdEndpointResolver::normalizeTimeout(
                $options['timeout'] ?? $resolved['timeout'] ?? null
            ),
            bodyMode: 'form', // 这条链路历史上一直用 form 提交，保持不变
        );

        $outbound = $transport->prepare($adapter, $outbound, $third);

        if ($outbound->path !== $path) {
            $url = ThirdEndpointResolver::buildUrl($third, $outbound->path);
        }

        try {
            $request = Http::timeout($outbound->timeout);

            if ($outbound->bodyMode === 'form') {
                $request = $request->asForm();
            }

            if ($outbound->headers) {
                $request = $request->withHeaders($outbound->headers);
            }

            $response = match (true) {
                $outbound->isPost() => $request->post($url, $outbound->params),
                $outbound->paramsInBody => $request
                    ->withBody($outbound->encodedBody(), $outbound->bodyContentType())
                    ->get($url),
                default => $request->get($url, $outbound->params),
            };

            $inbound = InboundResponse::fromHttp($response);
            $result = $transport->parse($adapter, $inbound, $outbound, $third);

            return [
                'success' => $result->isOk(),
                'http_code' => $response->status(),
                'body' => $result->isOk() ? $result->toLegacyArray() : $inbound->payload(),
                'raw' => $response->body(),
                'url' => $url,
                'error' => $result->isOk() ? '' : $result->message,
            ];
        } catch (\Throwable $e) {
            $result = $transport->transportFailed($adapter, $e->getMessage(), $outbound, $third);

            return $this->failed($result->message);
        }
    }

    /**
     * @param array<string, mixed> $map
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function renderMap(array $map, array $data): array
    {
        $out = [];

        foreach ($map as $key => $template) {
            $out[(string) $key] = $this->renderString((string) $template, $data);
        }

        return $out;
    }

    private function renderString(string $template, array $data): string
    {
        // 纯 {placeholder} 直接替换（路径与参数占位）
        $value = (string) preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', function ($m) use ($data) {
            return (string) (data_get($data, $m[1]) ?? '');
        }, $template);

        return $this->renderer->render($value, $data, []);
    }

    /**
     * @return array{success:bool,http_code:int,body:mixed,raw:string,url:string,error:string}
     */
    private function failed(string $error): array
    {
        return ['success' => false, 'http_code' => 0, 'body' => null, 'raw' => '', 'url' => '', 'error' => $error];
    }
}
