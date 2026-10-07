<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers;

use Illuminate\Support\Facades\Http;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureDriver;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Models\ThirdApiEndpoints;
use Modules\Telegram\Services\Feature\TemplateRenderer;

/**
 * 三方接口请求驱动（第二块：斜杠命令 → 上游 API）
 *
 * 后台只需配置：选三方配置（拿地址+token）、选接口（方法+路径）、
 * 路径参数映射、回复模板，即可完成一个命令，无需写代码。
 *
 * 三方配置与功能目前是全局关系；若后续要按群绑定不同上游，
 * 只需在 features_binds.config 里覆盖 third_config_id（已预留）。
 */
class HttpRequestDriver implements FeatureDriver
{
    public static function key(): string
    {
        return 'http.request';
    }

    public static function label(): string
    {
        return '调用三方接口';
    }

    public static function group(): string
    {
        return '斜杠命令';
    }

    public static function triggers(): array
    {
        return ['command', 'manual'];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'third_config_id',
                'label' => '三方配置',
                'type' => 'select',
                'source' => 'third_api_configs',
                'required' => true,
                'hint' => '在「三方配置」里维护地址与 token',
            ],
            [
                'key' => 'endpoint_id',
                'label' => '接口',
                'type' => 'endpoint',
                'source' => 'third_api_endpoints',
                'required' => true,
                'hint' => '在「三方配置」下维护接口（方法 + 路径模板）',
            ],
            [
                'key' => 'path_params',
                'label' => '路径参数映射',
                'type' => 'keyvalue',
                'required' => false,
                'hint' => '左=路径占位名，右=数据来源，如 {{@merchant_id}}',
            ],
            [
                'key' => 'body',
                'label' => '请求体',
                'type' => 'template',
                'required' => false,
                'hint' => 'POST 时发送，支持 {{@field}} 占位',
            ],
            [
                'key' => 'send_token',
                'label' => '携带 token',
                'type' => 'switch',
                'required' => false,
                'default' => true,
            ],
            [
                'key' => 'token_placeholder',
                'label' => 'token 位置',
                'type' => 'select',
                'source' => 'token_positions',
                'required' => false,
                'default' => 'header',
                'hint' => 'header / query / body',
            ],
            [
                'key' => 'token_header',
                'label' => 'token 请求头名',
                'type' => 'text',
                'required' => false,
                'default' => 'Authorization',
            ],
            [
                'key' => 'reply_template',
                'label' => '回复模板',
                'type' => 'template',
                'required' => false,
                'default' => '{{_raw}}',
                'hint' => '如 余额：{{data.balance}} 元；{{_raw}} 直接输出响应体',
            ],
        ];
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (empty($config['third_config_id'])) {
            $errors[] = '必须选择三方配置';
        }

        if (empty($config['endpoint_id'])) {
            $errors[] = '必须选择接口';
        }

        return $errors;
    }

    public function execute(FeatureContext $context): FeatureResult
    {
        $third = $this->resolveThirdConfig($context);
        if (! $third) {
            return FeatureResult::fail('三方配置不存在或已删除');
        }

        $endpoint = ThirdApiEndpoints::query()
            ->where('third_config_id', $third->id)
            ->where('id', $context->config('endpoint_id'))
            ->where('enabled', true)
            ->first();

        if (! $endpoint) {
            return FeatureResult::fail('接口不存在或已停用');
        }

        $renderer = app(TemplateRenderer::class);
        $extra = $this->buildExtra($context);

        // 路径模板渲染：{userID} 与 {{@merchant_id}} 两种写法都支持
        $path = $renderer->render((string) $endpoint->path_template, [], $extra);
        $path = $this->replaceBraceParams($path, $context, $extra);

        $url = rtrim((string) $third->api_url, '/') . '/' . ltrim($path, '/');

        $headers = is_array($endpoint->headers) ? $endpoint->headers : [];
        $query = is_array($endpoint->query) ? $endpoint->query : [];

        $token = (string) $third->token;
        if ($context->config('send_token', true) && $token !== '') {
            match ((string) $context->config('token_placeholder', 'header')) {
                'query' => $query['api_token'] = $token,
                'body' => $extra['_token'] = $token,
                default => $headers[strtolower((string) $context->config('token_header', 'Authorization'))] = $token,
            };
        }

        $method = strtoupper((string) ($endpoint->method ?: 'GET'));
        $body = $context->config('body')
            ? $renderer->render((string) $context->config('body'), [], $extra)
            : null;

        try {
            $request = Http::timeout((int) ($endpoint->timeout ?: 30));

            if ($headers) {
                $request = $request->withHeaders($headers);
            }

            $response = match ($method) {
                'POST' => $body !== null ? $request->post($url, $body) : $request->post($url),
                'PUT' => $request->put($url, $body ?? []),
                'DELETE' => $request->delete($url),
                default => $request->get($url, $query ?: []),
            };
        } catch (\Throwable $e) {
            return FeatureResult::fail('请求三方接口失败：' . $e->getMessage());
        }

        $payload = $response->json() ?? ['_raw' => $response->body()];

        if (! $response->successful()) {
            return FeatureResult::fail(
                '三方接口返回异常（' . $response->status() . '）：' . $response->body(),
                ['status' => $response->status()]
            );
        }

        $template = (string) $context->config('reply_template', '{{_raw}}');
        $message = $renderer->render($template, is_array($payload) ? $payload : [], $extra);

        return FeatureResult::reply($message, is_array($payload) ? $payload : [], [
            'status' => $response->status(),
            'endpoint_id' => $endpoint->id,
        ]);
    }

    /**
     * 绑定级配置可覆盖三方配置（为「按群绑定不同上游」预留）
     */
    private function resolveThirdConfig(FeatureContext $context): ?ThirdApiConfig
    {
        $id = $context->config('third_config_override') ?? $context->config('third_config_id');

        return $id ? ThirdApiConfig::query()->find($id) : null;
    }

    /**
     * 支持 {userID} 形式的路径占位（GetBalance/GetOrder 现有写法）
     */
    private function replaceBraceParams(string $path, FeatureContext $context, array $extra): string
    {
        return (string) preg_replace_callback(
            '/\{([A-Za-z0-9_]+)\}/',
            function (array $m) use ($extra, $context) {
                $key = $m[1];
                $value = $extra[$key] ?? $context->value($key);

                return rawurlencode((string) ($value ?? ''));
            },
            $path
        );
    }

    /**
     * 模板数据源：已存数据 + 命令参数 + 上下文
     */
    private function buildExtra(FeatureContext $context): array
    {
        $extra = $context->stored;

        $extra['args'] = $context->args;
        $extra['chat_id'] = $context->chatId;
        $extra['user_id'] = $context->userId;

        foreach ($context->params as $i => $p) {
            $name = $p['name'] ?? null;
            if ($name !== null && isset($context->args[$i])) {
                $extra[$name] = $context->args[$i];
            }
        }

        return $extra;
    }
}