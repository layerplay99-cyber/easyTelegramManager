<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\GroupConfigs;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\Feature\UpstreamResolver;
use Modules\Telegram\Services\LogMessageService;
use Modules\Telegram\Services\MerchantRouteService;
use Modules\Telegram\Services\OperatorPolicy;
use Modules\Telegram\Services\Translate\LocalizedReply;

/**
 * /bd <商户号> —— 把当前群绑定到商户号
 *
 * 旧实现是 Cache::forever('mid', chat_id)：单机单商户勉强能用，多商户多 bot 下
 * 互相覆盖、清缓存即丢、也无法审计。现在落在 merchant_routes 表：
 *   · 一个商户号一行，唯一约束保证不会被两个群抢绑
 *   · 同时记住「用哪个机器人发、走哪个上游」
 *   · 上游回调来时按商户号反查，多商户多 bot 各走各的
 *
 * 不带参数 = 查看本群当前绑定。
 * 群内文案按「群设置 → 语种配置」取自 lang/{locale}/merchant.php，
 * 后台仍可填固定的 reply_template / not_exists_tip 覆盖（填了就不翻译）。
 *
 * 【示例看点 · 路线 A】（docs/feature-dev.md §2）
 *   · 读命令实参用 $context->arg(0)，不用 value() —— 已存数据不能顶掉「本次输入」，
 *     否则「查看绑定」会被当成重新绑定
 *   · 上游确认与写路由用同一个 UpstreamResolver 解析，保证「校验」和「绑定」是同一条上游
 *   · putData() 把商户号存进功能数据，其它功能可用 {{@merchant_id}} 引用
 *   · 插件版对照：resources/plugin-examples/merchant-demo/（自包含、不依赖内部服务）
 */
class BindMerchant extends BaseCustomFeature implements EndpointProvider
{
    use LocalizedReply;

    public static function key(): string
    {
        return 'custom.bind_merchant';
    }

    /**
     * 本功能要用到的上游接口
     *
     * 与 GetMerchantBalance 一样：接口规范跟着「用它的那个类」走。
     * 写了这里，刷新后台「功能列表」后接口就会进 third_api_endpoints，
     * 之后在「上游接口配置」里可以按每个上游单独改这条路径
     * （路径支持 {merchant_id} 占位，例如 api/merchants/{merchant_id}/info）。
     */
    public static function endpoints(): array
    {
        return [
            'merchant.check' => [
                'name' => '校验商户是否存在',
                'method' => 'GET',
                'path_template' => 'api/merchant/check',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true, 'desc' => '商户号', 'map_to' => 'merchantId'],
                ],
                'response_schema' => [
                    'data' => [
                        'id' => '上游返回的商户号（与输入的商户号一致才算存在）',
                    ],
                ],
                'remark' => '绑定前先向上游确认商户号是否存在，不存在则拒绝绑定',
            ],
        ];
    }

    public static function label(): string
    {
        return '绑定商户号';
    }

    public static function featureName(): string
    {
        return '绑定商户号';
    }

    public static function featureDescription(): string
    {
        return '把当前群绑定到商户号，按群语种回复（/bd <商户号>）';
    }

    public static function category(): string
    {
        return 'bot';
    }

    public static function group(): string
    {
        return '商户';
    }

    public static function commands(): array
    {
        return [
            [
                'command' => 'bd',
                'usage' => '/bd <商户号>',
                'description' => '绑定/查看本群的商户号（按群语种回复）',
                'params' => [
                    ['name' => 'merchant_id', 'required' => false, 'desc' => '商户号，留空表示查看'],
                ],
            ],
        ];
    }

    public static function defaultConfig(): array
    {
        return [
            'require_admin' => true,
            'allow_overwrite' => true,
            'upstream_id' => null,
            'endpoint_code' => 'merchant.check',
            // 留空走语言包；库里存着的旧中文默认值由 customText() 识别
            'not_exists_tip' => '',
            'reply_template' => '',
        ];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'require_admin',
                'label' => '仅群管理员可绑定',
                'type' => 'switch',
                'required' => false,
                'default' => true,
            ],
            [
                'key' => 'allow_overwrite',
                'label' => '允许改绑',
                'type' => 'switch',
                'required' => false,
                'default' => true,
                'hint' => '关闭后，已被其它群绑定的商户号不能再绑到本群',
            ],
            [
                'key' => 'upstream_id',
                'label' => '承接上游',
                'type' => 'select',
                'source' => 'third_api_configs',
                'required' => false,
                'hint' => '已废弃：实际承接上游由「功能绑定 → 机器人候选池」统一解析（UpstreamResolver），这里填的值不再生效',
            ],
            [
                'key' => 'endpoint_code',
                'label' => '平台接口',
                'type' => 'select',
                'source' => 'platform_endpoints',
                'required' => true,
                'default' => 'merchant.check',
                'hint' => '绑定前用它向该上游确认商户号是否存在',
            ],
            [
                'key' => 'not_exists_tip',
                'label' => '商户不存在时的提示',
                'type' => 'text',
                'required' => false,
                'default' => '',
                'hint' => '留空按群语种自动翻译；填写则固定使用该文案',
            ],
            [
                'key' => 'reply_template',
                'label' => '回复模板',
                'type' => 'template',
                'required' => false,
                'default' => '',
                'hint' => '留空按群语种自动翻译；填写则固定使用该文案。可用变量 {{merchant_id}}',
            ],
        ];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $chatId = (string) $context->chatId;
        $routes = app(MerchantRouteService::class);

        // 只认命令实参：不带参数就是「查看」，绝不能拿已存数据 / 群配置里兜底的
        // merchant_id 当成本次输入的商户号 —— 那会把「/bd」变成「/bd <商户号>」，
        // 平白去打一次上游还重新绑一遍。
        $merchantId = trim((string) ($context->arg(0) ?: ''));

        // 不带参数：查看当前绑定，只读本地，不请求上游
        if ($merchantId === '') {
            $local = $this->localMerchantId($context, $chatId);

            return $local === ''
                ? $this->localizedReply($this->t($context, 'bind.not_bound_usage', [
                    'usage' => $this->bindUsage() ?: '/bd',
                ]))
                : $this->boundReply($context, $local);
        }

        // 带参数：本地已经绑的就是这个号 → 直接用本地记录，不再向上游确认
        if (in_array($merchantId, $routes->forChat($chatId), true)) {
            return $this->boundReply($context, $merchantId);
        }

        // 先向上游确认这个商户号是否真的存在（用后台选的接口 + 上游）
        $check = $this->callEndpoint(
            (string) $context->config('endpoint_code', 'merchant.check'),
            ['merchant_id' => $merchantId],
            $context
        );

        if (isset($check['_error'])) {
            return $this->localizedFail($this->t($context, 'bind.check_failed') . $check['_error']);
        }

        if (! $this->merchantExists($check, $merchantId)) {
            return $this->localizedFail(
                $this->customText($context, 'not_exists_tip') ?: $this->t($context, 'bind.not_exists')
            );
        }

        // 与 callEndpoint() 用同一个解析器，保证「校验商户」和「写路由」是同一条上游。
        // features.config.upstream_id 已废弃（跨机器人共享的全局配置），不能再优先用它，
        // 否则会出现校验走 A、回调按商户号反查走 B。
        $thirdConfigId = app(UpstreamResolver::class)->resolveId(
            $context->bind,
            $context->bot
        );

        try {
            $routes->bind(
                (int) ($context->bot?->id ?? 0),
                $chatId,
                $merchantId,
                $thirdConfigId ? (int) $thirdConfigId : null,
                $context->feature->id,
                (bool) $context->config('allow_overwrite', true)
            );
        } catch (\Throwable $e) {
            return $this->localizedFail($e->getMessage());
        }

        // 同时写一份到功能数据，方便 {{@merchant_id}} 在别的功能里直接引用
        $this->putData($context, ['merchant_id' => $merchantId]);

        // 回写到群配置：后台「群列表 → 配置」里的商户ID 才有东西显示
        // （前端读的就是 group_configs.mid，两张表指的是同一个商户号）
        $this->syncGroupConfig($context, $chatId, $merchantId);

        return $this->boundReply($context, $merchantId);
    }

    /**
     * 绑定成功 / 查看绑定时的回复
     */
    protected function boundReply(FeatureContext $context, string $merchantId): FeatureResult
    {
        $custom = $this->customText($context, 'reply_template');

        $text = $custom !== ''
            ? $this->render($custom, ['merchant_id' => $merchantId], $context)
            : $this->t($context, 'bind.bound', ['merchant_id' => $merchantId]);

        return $this->localizedReply($text);
    }

    /**
     * 本群当前绑定的商户号（只读本地，不打上游）
     *
     * 优先级：merchant_routes（/bd 写的，唯一数据源）
     *       → 本功能已存数据（含群配置里兜底出来的那个）
     *       → group_configs.mid
     */
    protected function localMerchantId(FeatureContext $context, string $chatId): string
    {
        $bound = app(MerchantRouteService::class)->forChat($chatId);

        if ($bound !== []) {
            return (string) $bound[0];
        }

        $stored = trim((string) ($context->stored['merchant_id'] ?? ''));

        if ($stored !== '') {
            return $stored;
        }

        return (string) (GroupConfigs::merchantIdOf($chatId) ?? '');
    }

    /**
     * /bd 绑定成功后把商户号同步进群配置
     */
    protected function syncGroupConfig(FeatureContext $context, string $chatId, string $merchantId): void
    {
        try {
            $group = BotGroups::query()
                ->where('chat_id', $chatId)
                ->where('bot_id', $context->bot?->id)
                ->first();

            if (! $group) {
                return;
            }

            $config = GroupConfigs::query()->firstOrNew(['groupId' => $group->id]);

            if ((string) ($config->mid ?? '') === $merchantId) {
                return;
            }

            $config->forceFill([
                'mid' => $merchantId,
                // 后台保存时由框架填，这里跑在队列里没有登录态，显式继承群的归属
                'creator_id' => (int) ($config->creator_id ?: $group->creator_id ?: 1),
            ])->save();
        } catch (\Throwable $e) {
            app(LogMessageService::class)->createLaravelLog(
                'telegram_errors',
                ['chat_id' => $chatId, 'trace' => $e->getTraceAsString()],
                "同步商户号到群配置失败: {$e->getMessage()}",
                'error'
            );
        }
    }

    /**
     * 商户是否真的存在：上游返回结果里的商户号，必须等于命令输入的商户号
     *
     * /bd 的语义就是「拿输入的号去上游查一次」，只有查回来的号与输入一致才算命中，
     * 之后才走平台绑定（写 merchant_routes）。上游不给 id、或给的是另一个号，
     * 都视为不存在，不能绑。
     *
     * 只认平台标准字段 data.id：配了插件的上游由插件把厂商字段归一到这里；
     * 留空 = 直通的上游若把 id 放在响应顶层，这里也兼容一次（原文不改）。
     *
     * @param array<string, mixed> $response
     */
    protected function merchantExists(array $response, string $merchantId): bool
    {
        $data = (array) ($response['data'] ?? $response);
        $id = $data['id'] ?? null;

        return $id !== null && (string) $id === (string) $merchantId;
    }
}
