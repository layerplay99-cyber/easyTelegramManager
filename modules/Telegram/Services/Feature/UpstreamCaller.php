<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Models\ThirdApiEndpoints;

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

        try {
            $request = Http::timeout((int) ($options['timeout'] ?? $resolved['timeout'] ?: 30));

            $headers = $resolved['headers'];
            if ($third->token) {
                $headers['Authorization'] = $third->token;
            }
            if ($headers) {
                $request = $request->withHeaders($headers);
            }

            $response = $method === 'POST'
                ? $request->asForm()->post($url, $params)
                : $request->get($url, $params);

            return [
                'success' => $response->successful(),
                'http_code' => $response->status(),
                'body' => $response->json(),
                'raw' => $response->body(),
                'url' => $url,
                'error' => $response->successful() ? '' : '上游返回 HTTP ' . $response->status(),
            ];
        } catch (\Throwable $e) {
            return $this->failed($e->getMessage());
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
