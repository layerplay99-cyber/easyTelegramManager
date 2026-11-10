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
use Modules\Telegram\Services\Feature\ThirdEndpointResolver;
use Modules\Telegram\Services\Feature\UpstreamResolver;
use Modules\Telegram\Services\Upstream\UpstreamRequest as OutboundRequest;
use Modules\Telegram\Services\Upstream\UpstreamResponse as InboundResponse;
use Modules\Telegram\Services\Upstream\UpstreamTransport;

/**
 * 自定义功能基类
 *
 * 扩展一个功能只需两步：
 *   1) 在 Drivers/Custom/ 目录下新建一个类，继承本基类，实现 handle()
 *   2) 执行 php artisan telegram:sync-features
 *
 * 该类会自动：
 *   - 被驱动注册表发现（后台「功能列表」出现该执行器）
 *   - 按 uuid() 幂等写入 features 表
 *   - 按 commands() 声明写入 feature_commands 表
 *   - 命令分发时自动路由到 handle()
 *
 * 已封装：功能数据读写、调三方接口、回复、模板渲染。
 * 可选：覆写 responseHandlers() 让本功能自带上游响应解构（优先于适配器），
 * 上游返回格式不标准时不必再为每家上游写适配器。
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

    // ------------------------------------------------------------------
    // 上游能力声明（可选覆写，供 CustomFeatureRegistry 同步到库）
    // ------------------------------------------------------------------

    /**
     * 本功能会调用哪些平台接口 code
     *
     * 实现 EndpointProvider 的功能自动从 endpoints() 派生；钱包功能走
     * UpstreamWalletGateway（不实现该接口），需要自己覆写本方法。
     */
    public static function upstreamEndpoints(): array
    {
        if (in_array(\Modules\Telegram\Contracts\EndpointProvider::class, class_implements(static::class) ?: [], true)) {
            return array_values(array_keys(static::endpoints()));
        }

        return [];
    }

    /**
     * 是否需要交互上游。
     *
     * false = 官方 Telegram API / 纯本地功能（群发、私聊、踢人、本地钱包查询…），
     * 后台「上游绑定功能」不允许勾选，功能绑定也不能给它指定上游。
     */
    public static function needsUpstream(): bool
    {
        return static::upstreamEndpoints() !== [];
    }

    /**
     * 功能名称（后台显示）
     */
    abstract public static function featureName(): string;

    // ------------------------------------------------------------------
    // 计费声明（可选覆写，只作为首次同步的默认值）
    // ------------------------------------------------------------------

    /**
     * 功能计费级别：'free' 免费 / 'paid' 付费
     *
     * paid = 归属账号必须处于有效套餐期内才能执行（执行时校验，见 FeatureExecutor），
     * free = 永久可用（仍受「服务整体到期」拦截约束，两回事）。
     *
     * 代码里声明的只是**首次同步的默认值**：功能入库后以后台
     * 「功能列表」里的设置为准，之后同步不会覆盖后台改过的值。
     */
    public static function accessLevel(): string
    {
        return 'free';
    }

    /**
     * 功能逻辑
     */
    abstract public function handle(FeatureContext $context): FeatureResult;

    // ------------------------------------------------------------------
    // 可选：覆盖默认值
    // ------------------------------------------------------------------

    /**
     * 功能唯一标识（UUID），用于幂等 upsert
     *
     * 这是功能在整个系统里的「身份证」，决定 features 表里是哪一行
     * （入库为 custom:{uuid}）。后台配置——启用开关、绑定的机器人/群、
     * 命令内容、执行器配置——全都挂在那一行的 id 上，所以它必须稳定。
     *
     * 【什么时候不要动】
     *   改描述、改功能名称、改业务逻辑、改命令内容，都不需要也不应该改 uuid。
     *   功能代码是要长期维护的，uuid 只用来认人，不承载业务含义。
     *
     * 【什么时候必须显式覆盖】
     *   只有一种情况：重命名了类，或把它挪到别的命名空间。
     *   默认取短类名，一旦改名就等于换了个新功能——features 表会新建一行，
     *   后台原来配的东西（开关、绑定、命令）全都留在旧行上，等于丢了。
     *   这时把 uuid() 覆盖成**原来的值**即可接回去。
     *
     * 默认取短类名（不含命名空间），避免出现
     * Modules\Telegram\Services\Feature\Drivers\Custom\Xxx 这种把代码目录结构
     * 暴露到数据库里的长标识。想要更语义化的标识就直接覆盖，
     * 例如 return 'merchant_balance';
     */
    public static function uuid(): string
    {
        return class_basename(static::class);
    }

    /**
     * 该功能实际使用的执行器（写进 features.driver）
     *
     * 默认就是自己（一个类既是功能代码、也是执行器）；
     * 只想复用已有执行器时覆盖本方法即可，例如真人功能统一返回
     * TelegramApiDriver::key()，6 个功能共用一个执行器、靠 config.method 区分。
     */
    public static function driver(): string
    {
        return static::key();
    }

    /**
     * 是否作为「执行器」出现在后台下拉里
     *
     * 复用其它执行器的功能类（只声明配置、不含执行逻辑）返回 false，
     * 避免后台执行器下拉里出现一堆同质的选项。
     */
    public static function isDriver(): bool
    {
        return true;
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
     * 功能自带的响应解构声明表（可选，默认空 = 全部走上游适配器）
     *
     * endpointCode => 解构回调。回调签名：
     *   fn(InboundResponse $response, OutboundRequest $request, ThirdApiConfig $upstream): UpstreamResult|array
     *
     * 声明了的接口：callEndpoint() 优先用这里的解构，不再走 thirdapi_config.adapter
     * 的适配器——上游返回什么格式，功能自己最清楚，不必再为每家上游写
     * Adapter + ResponseMap。传输层失败（没发出 / 非 2xx / 非 JSON）已由
     * UpstreamTransport 统一判定，回调只负责「JSON body → 平台标准结构」。
     *
     * 回调返回：
     *   - array 带 _error 键 = 失败（与 callEndpoint 的错误约定一致）
     *   - array              = 成功，data 即返回值
     *   - UpstreamResult     = 直接使用
     * 回调抛异常 / 返回非法值时自动回退到上游适配器解构（UpstreamTransport 安全壳）。
     *
     * @return array<string, callable|string>
     */
    protected function responseHandlers(): array
    {
        return [];
    }

    /**
     * 取某接口的功能解构回调；支持闭包 / [$this, '方法名'] / '方法名'
     */
    protected function responseHandlerFor(string $endpointCode): ?callable
    {
        $handlers = $this->responseHandlers();

        $handler = $handlers[$endpointCode] ?? null;

        if ($handler === null) {
            return null;
        }

        if (is_string($handler)) {
            $handler = [$this, $handler];
        }

        if (! is_callable($handler)) {
            logger()->warning('[自定义功能] responseHandlers 声明的回调不可调用，已回退适配器', [
                'feature' => static::key(),
                'endpoint' => $endpointCode,
            ]);

            return null;
        }

        // 包装成闭包：解构最终由 UpstreamTransport（类外）调用，
        // 不包装的话 protected 方法名声明会在参数校验时因可见性 TypeError。
        // 闭包在本类作用域里创建，protected / private 方法都可达。
        return fn (...$args) => $handler(...$args);
    }

    /**
     * 调用平台接口（返回响应体数组，失败时返回 ['_error' => 原因]）
     *
     * 平台化约定：上游取当前机器人绑定的三方配置（各用户不同），
     * 接口按平台 code 引用（全局统一）。因此不同用户执行同一功能时
     * 行为一致，只是请求各自的上游。
     *
     * @param array<string, mixed> $params     路径占位/查询参数
     * @param string               $pathSuffix 追加到路径末尾的动态资源段（已 rawurlencode），
     *                                        例如订单号；不会进 query
     * @return array<string, mixed>
     */
    protected function callEndpoint(
        string $endpointCode,
        array $params = [],
        ?FeatureContext $context = null,
        string $method = 'GET',
        string $pathSuffix = ''
    ): array {
        $endpoint = ThirdApiEndpoints::query()
            ->where('code', $endpointCode)
            ->where('enabled', true)
            ->first();

        if (! $endpoint) {
            return ['_error' => "平台接口不存在：{$endpointCode}"];
        }

        // 上游解析统一走 UpstreamResolver：
        //   绑定级（该功能绑定的具体上游）> 机器人候选池里的默认上游。
        // 候选池有值时，绑定/镜像里指定的上游必须属于该机器人，防止串到别人的上游。
        // features.config.upstream_id 是跨机器人共享的全局配置，已废弃。
        $thirdConfigId = app(UpstreamResolver::class)->resolveId(
            $context?->bind,
            $context?->bot
        );

        $third = $thirdConfigId > 0 ? ThirdApiConfig::query()->find($thirdConfigId) : null;

        if (! $third) {
            return ['_error' => '尚未指定三方上游：请在「机器人 → 可绑定上游」里配置，并在功能绑定上指定承接上游'];
        }

        // 取「该上游 + 该接口」最终生效的配置：上游专属路径优先，回退平台默认
        $resolved = ThirdEndpointResolver::resolve($third, $endpoint);

        if (! $resolved['enabled']) {
            return ['_error' => '当前上游不支持该接口：' . $endpoint->code];
        }

        $renderer = app(TemplateRenderer::class);
        $path = $renderer->render($resolved['path'], [], $params);

        // 路径模板里已经用到的参数名（{name} 或 {{name}} / {{@name}}）
        //
        // 这些参数等下会被填进 URL 路径，不能再往 GET 的 query 里塞一份，
        // 否则路径写成 api/webhook/users/{merchant_id} 时会请求成
        //   /api/webhook/users/158?merchant_id=158
        $usedInPath = [];

        foreach (['/\{([A-Za-z0-9_]+)\}/', '/\{\{\s*@?([A-Za-z0-9_.]+?)\s*\}\}/'] as $pattern) {
            if (preg_match_all($pattern, (string) $resolved['path'], $matched)) {
                foreach ($matched[1] as $name) {
                    $usedInPath[(string) $name] = true;
                }
            }
        }

        // 支持 {name} 形式的占位
        $path = (string) preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', function ($m) use ($params) {
            return rawurlencode((string) ($params[$m[1]] ?? ''));
        }, $path);

        // 动态资源 ID（订单号等）由功能代码拼好并编码后追加到路径末尾。
        // 它不写进后台 path 模板，也不放进 $params，所以不会重复出现在 query 上。
        if ($pathSuffix !== '') {
            $path = ThirdEndpointResolver::appendSegment($path, $pathSuffix);
        }

        $url = ThirdEndpointResolver::buildUrl($third, $path);

        // 上游若单独指定了请求方法则以它为准；否则沿用调用方传入的方法（兼容既有自定义功能）
        $platformMethod = strtoupper((string) ($endpoint->method ?: 'GET'));
        $requestMethod = $resolved['method'] !== $platformMethod
            ? $resolved['method']
            : strtoupper($method);

        // ---- 上游协议适配：把平台标准请求交给该厂商的插件改写 ----
        // 功能代码从头到尾只用标准参数名，字段名 / 位置 / 编码 / 签名的差异
        // 全部在插件里。adapter 留空或插件异常时 UpstreamTransport 会回退直通。
        $transport = app(UpstreamTransport::class);
        $adapter = $transport->adapterFor($third);

        $outbound = new OutboundRequest(
            endpointCode: $endpoint->code,
            method: $requestMethod,
            path: $path,
            url: $url,
            params: $params,
            query: [],
            headers: (array) ($resolved['headers'] ?? []),
            pathParams: array_keys($usedInPath),
            timeout: (int) $resolved['timeout'],
        );

        $outbound = $transport->prepare($adapter, $outbound, $third);

        // 插件可以整段改写路径（例如厂商要求带版本号前缀），改了就重新拼 URL
        if ($outbound->path !== $path) {
            $url = ThirdEndpointResolver::buildUrl($third, $outbound->path);
        }

        // GET：只把没被路径吃掉的参数放 query（POST 的 body 保持原样，上游通常都要）
        $queryParams = array_merge($outbound->queryParams(), $outbound->query);

        try {
            $request = Http::timeout($outbound->timeout);

            if ($outbound->bodyMode === 'form') {
                $request = $request->asForm();
            }

            if ($outbound->headers) {
                $request = $request->withHeaders($outbound->headers);
            }

            if ($third->token) {
                $request = $request->withToken($third->token);
            }

            $response = match (true) {
                $outbound->isPost() => $request->post($url, $outbound->params),
                // 参数放 post-data 的 GET：URL 保持干净，withToken / 头照常带
                $outbound->paramsInBody => $request
                    ->withBody($outbound->encodedBody(), $outbound->bodyContentType())
                    ->get($url),
                default => $request->get($url, $queryParams),
            };

            $inbound = InboundResponse::fromHttp($response);

            // 响应解构优先级：功能自带的 responseHandlers() > 上游适配器 > 直通。
            // 功能自己声明了解构的接口不再经过适配器——上游返回什么格式，功能
            // 自己最清楚（见 responseHandlers() 注释）；没声明的走适配器（默认直通）。
            $handler = $this->responseHandlerFor($endpoint->code);

            $result = $handler !== null
                ? $transport->parseWithFeatureMapper($handler, $adapter, $inbound, $outbound, $third)
                : $transport->parse($adapter, $inbound, $outbound, $third);

            return $result->toLegacyArray();
        } catch (\Throwable $e) {
            return $transport->transportFailed($adapter, $e->getMessage(), $outbound, $third)->toLegacyArray();
        }
    }

    /**
     * 截断响应体片段，避免把整个错误页塞进回复 / 日志
     */
    protected function excerpt(string $text, int $limit = 200): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_substr($text, 0, $limit);
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
