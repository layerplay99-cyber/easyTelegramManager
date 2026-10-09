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
 * 平台化设计（功能与上游彻底解耦）：
 *   - 本功能只引用「平台接口规范 code」，不持有任何上游信息
 *   - 上游（base_url + token）由当前机器人携带：bots.third_config_id
 *   - 因此 A、B 两个用户执行同一个命令时，行为完全一致，只是请求各自的上游
 *
 * 接入新上游只需在「三方配置」里填地址/token，无需改功能、无需改代码。
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
                'key' => 'endpoint_code',
                'label' => '平台接口',
                'type' => 'select',
                'source' => 'platform_endpoints',
                'required' => true,
                'hint' => '平台统一维护的接口规范；不同用户用同一个功能，只是请求各自的上游',
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

        if (empty($config['endpoint_code'])) {
            $errors[] = '必须选择平台接口';
        }

        return $errors;
    }

    public function execute(FeatureContext $context): FeatureResult
    {
        // 1) 上游：绑定级（该功能单独指定）优先，回退机器人级默认（各用户不同）
        $third = $this->resolveThirdConfig($context);

        if (! $third) {
            return FeatureResult::fail(
                $context->bot
                    ? '尚未指定三方上游：请在「机器人列表」的上游列设置默认值，或在该机器人的「功能配置」里为当前功能单独指定'
                    : '未能确定上游配置（缺少机器人上下文）'
            );
        }

        // 2) 接口规范：平台统一定义，全局唯一（各用户相同）
        $endpoint = ThirdApiEndpoints::query()
            ->where('code', $context->config('endpoint_code'))
            ->where('enabled', true)
            ->first();

        if (! $endpoint) {
            return FeatureResult::fail('平台接口不存在或已停用：' . $context->config('endpoint_code'));
        }

        $renderer = app(TemplateRenderer::class);
        $extra = $this->buildExtra($context);

        // 3) 路径渲染：{占位} 与 {{@字段}} 两种写法都支持
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
            return FeatureResult::fail('请求上游失败：' . $e->getMessage());
        }

        $payload = $response->json() ?? ['_raw' => $response->body()];

        if (! $response->successful()) {
            return FeatureResult::fail(
                sprintf('上游返回异常（%d）：%s', $response->status(), mb_substr($response->body(), 0, 200)),
                ['status' => $response->status()]
            );
        }

        $template = (string) $context->config('reply_template', '{{_raw}}');
        $message = $renderer->render($template, is_array($payload) ? $payload : [], $extra);

        return FeatureResult::reply($message, is_array($payload) ? $payload : [], [
            'status' => $response->status(),
            'endpoint_code' => $endpoint->code,
        ]);
    }

    /**
     * 解析上游配置
     *
     * 优先级：绑定级（(实体,功能) 粒度，third_config_id）
     *        > 实体级默认（bots.third_config_id）
     *
     * 这样同一个功能被多个实体绑定时，每个实体可指定各自的上游，
     * 且同一实体的不同功能也能使用不同上游。
     */
    private function resolveThirdConfig(FeatureContext $context): ?ThirdApiConfig
    {
        $thirdConfigId = $this->resolveEffectiveThirdConfigId($context);

        if (! $thirdConfigId) {
            return null;
        }

        return ThirdApiConfig::query()->find($thirdConfigId);
    }

    private function resolveEffectiveThirdConfigId(FeatureContext $context): ?int
    {
        // 1) 绑定级（最精细）：该(实体,功能)组合指定的上游
        $bindId = $context->bind?->third_config_id;
        if ($bindId) {
            return $bindId;
        }

        // 2) 实体级默认：机器人本身绑定的上游
        $botId = $context->bot?->third_config_id;
        if ($botId) {
            return $botId;
        }

        return null;
    }

    /**
     * 支持 {userID} 形式的路径占位
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
     * 模板数据源：已存数据（如已绑定商户号）+ 命令参数 + 上下文
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