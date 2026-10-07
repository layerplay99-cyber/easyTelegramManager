<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers;

use Illuminate\Support\Facades\Http;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureDriver;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Models\ThirdApiEndpoints;
use Modules\Telegram\Services\Feature\FeatureDataStore;
use Modules\Telegram\Services\Feature\TemplateRenderer;

/**
 * 自定义功能基类
 *
 * 扩展一个功能只需两步：
 *   1) 在 Drivers/Custom/ 目录下新建一个类，继承本基类，实现 handle()
 *   2) 执行 php artisan telegram:sync-features
 *
 * 该类会自动：
 *   - 被驱动注册表发现（后台「功能列表」出现该执行器）
 *   - 按 featureKey() 幂等写入 features 表
 *   - 按 commands() 声明写入 feature_commands 表
 *   - 命令分发时自动路由到 handle()
 *
 * 已封装：功能数据读写、调三方接口、回复、模板渲染。
 *
 * 示例：
 *
 * class MyBalance extends BaseCustomFeature
 * {
 *     public static function featureName(): string
 *     {
 *         return '查商户余额';
 *     }
 *
 *     public static function commands(): array
 *     {
 *         return [[
 *             'command'        => 'ye',
 *             'usage'          => '/ye',
 *             'description'    => '查询当前群绑定商户的余额',
 *             'params'         => [],
 *             'reply_template' => '余额：{{data.balance}} 元',
 *         ]];
 *     }
 *
 *     public function handle(FeatureContext $ctx): FeatureResult
 *     {
 *         $mid = $ctx->value('merchant_id');
 *
 *         if (! $mid) {
 *             return $this->fail('本群还没有绑定商户号');
 *         }
 *
 *         $data = $this->callEndpoint($this->endpointId(), ['userID' => $mid]);
 *
 *         if (isset($data['_error'])) {
 *             return $this->fail('查询失败：' . $data['_error']);
 *         }
 *
 *         return $this->reply($this->render(
 *             (string) $ctx->config('reply_template', '{{_raw}}'),
 *             $data,
 *             $ctx
 *         ));
 *     }
 *
 *     private function endpointId(): int
 *     {
 *         return (int) $this->config('endpoint_id');
 *     }
 * }
 */
abstract class BaseCustomFeature implements FeatureDriver
{
    public function __construct(protected FeatureDataStore $store)
    {
    }

    // ------------------------------------------------------------------
    // 必填：开发者实现
    // ------------------------------------------------------------------

    /**
     * 功能名称（后台显示）
     */
    abstract public static function featureName(): string;

    /**
     * 功能逻辑
     */
    abstract public function handle(FeatureContext $context): FeatureResult;

    // ------------------------------------------------------------------
    // 可选：覆盖默认值
    // ------------------------------------------------------------------

    /**
     * 功能唯一标识，用于幂等 upsert，默认用类名
     */
    public static function featureKey(): string
    {
        return static::class;
    }

    public static function featureDescription(): string
    {
        return '';
    }

    /**
     * 归属通道：bot（机器人）/ realMan（真人号）
     */
    public static function category(): string
    {
        return 'bot';
    }

    public static function triggerName(): string
    {
        return 'command';
    }

    public static function group(): string
    {
        return '自定义';
    }

    /**
     * 支持的触发方式
     *
     * @return array<int, string>
     */
    public static function triggers(): array
    {
        return ['command', 'manual'];
    }

    /**
     * 默认配置（写入 features.config）
     *
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [];
    }

    /**
     * 斜杠命令声明
     *
     * 每个命令：command / usage / description / params / reply_template
     *
     * @return array<int, array<string, mixed>>
     */
    public static function commands(): array
    {
        return [];
    }

    public function validate(array $config): array
    {
        return [];
    }

    public function execute(FeatureContext $context): FeatureResult
    {
        return $this->handle($context);
    }

    // ------------------------------------------------------------------
    // 便捷能力
    // ------------------------------------------------------------------

    /**
     * 取该作用域下本功能已保存的全部数据
     *
     * @return array<string, mixed>
     */
    protected function data(FeatureContext $context): array
    {
        return $context->stored;
    }

    /**
     * 读取某个已保存的字段（命令实参优先，其次已存数据）
     */
    protected function value(FeatureContext $context, string $name, mixed $default = null): mixed
    {
        return $context->value($name, $default);
    }

    /**
     * 保存数据到数据库
     *
     * @param array<string, mixed> $data
     */
    protected function putData(FeatureContext $context, array $data, ?string $scopeType = null): void
    {
        $scopeId = $this->scopeId($context, $scopeType);

        if ($scopeId !== null && $scopeId !== '') {
            $this->store->put(
                $context->feature->id,
                $scopeType ?? (string) $context->config('scope_type', 'group'),
                $scopeId,
                $data
            );
        }
    }

    /**
     * 清除该作用域的数据
     */
    protected function forgetData(FeatureContext $context, ?string $scopeType = null): void
    {
        $scopeId = $this->scopeId($context, $scopeType);

        if ($scopeId !== null && $scopeId !== '') {
            $this->store->forget(
                $context->feature->id,
                $scopeType ?? (string) $context->config('scope_type', 'group'),
                $scopeId
            );
        }
    }

    private function scopeId(FeatureContext $context, ?string $scopeType): int|string|null
    {
        return match ($scopeType ?? (string) $context->config('scope_type', 'group')) {
            'bot' => $context->bot?->id,
            'user' => $context->userId,
            'global' => 'global',
            default => $context->chatId,
        };
    }

    /**
     * 调用三方接口，返回响应体数组（失败时返回 ['_error' => 原因]）
     *
     * @param array<string, mixed> $params 路径/查询参数
     * @return array<string, mixed>
     */
    protected function callEndpoint(
        int $endpointId,
        array $params = [],
        string $method = 'GET',
        ?string $thirdConfigId = null
    ): array {
        $endpoint = ThirdApiEndpoints::query()->find($endpointId);

        if (! $endpoint) {
            return ['_error' => '接口不存在'];
        }

        $third = $thirdConfigId
            ? ThirdApiConfig::query()->find($thirdConfigId)
            : ThirdApiConfig::query()->find($endpoint->third_config_id);

        if (! $third) {
            return ['_error' => '三方配置不存在'];
        }

        $renderer = app(TemplateRenderer::class);
        $path = $renderer->render((string) $endpoint->path_template, [], $params);

        // 支持 {name} 形式的占位
        $path = (string) preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', function ($m) use ($params) {
            return rawurlencode((string) ($params[$m[1]] ?? ''));
        }, $path);

        $url = rtrim((string) $third->api_url, '/') . '/' . ltrim($path, '/');

        try {
            $request = Http::timeout((int) ($endpoint->timeout ?: 30));

            if (is_array($endpoint->headers) && $endpoint->headers) {
                $request = $request->withHeaders($endpoint->headers);
            }

            if ($third->token) {
                $request = $request->withHeaders(['Authorization' => $third->token]);
            }

            $response = strtoupper($method) === 'POST'
                ? $request->post($url, $params)
                : $request->get($url, $params);

            return (array) ($response->json() ?? ['_raw' => $response->body()]);
        } catch (\Throwable $e) {
            return ['_error' => $e->getMessage()];
        }
    }

    /**
     * 渲染模板（支持 {{data.x}} 与 {{@field}}）
     *
     * @param array<string, mixed> $data
     */
    protected function render(string $template, array $data = [], ?FeatureContext $context = null): string
    {
        return app(TemplateRenderer::class)->render($template, $data, $context?->stored ?? []);
    }

    /**
     * 构造「回复当前会话」的结果
     */
    protected function reply(string $text, array $data = []): FeatureResult
    {
        return FeatureResult::reply($text, $data);
    }

    /**
     * 构造失败结果（会记入 feature_logs）
     */
    protected function fail(string $message): FeatureResult
    {
        return FeatureResult::fail($message);
    }

    /**
     * 构造「成功但不回复」的结果
     */
    protected function ok(array $data = []): FeatureResult
    {
        return FeatureResult::ok($data);
    }
}