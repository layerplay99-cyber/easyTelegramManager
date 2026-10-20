<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\GroupConfigs;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\LogMessageService;
use Modules\Telegram\Services\MerchantRouteService;
use Modules\Telegram\Services\OperatorPolicy;

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
 */
class BindMerchant extends BaseCustomFeature implements EndpointProvider
{
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
                    'exists' => '是否存在',
                    'name' => '商户名称',
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
        return '把当前群绑定到商户号（/bd <商户号>）';
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
                'description' => '绑定/查看本群的商户号',
                'params' => [
                    ['name' => 'merchant_id', 'required' => false, 'desc' => '商户号，留空表示查看'],
                ],
                'reply_template' => '✅ 本群已绑定商户号：{{merchant_id}}',
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
            'not_exists_tip' => '该商户不存在',
            'reply_template' => '✅ 本群已绑定商户号：{{merchant_id}}',
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
                'hint' => '留空则依次用「功能绑定上的上游 → 机器人默认上游」',
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
                'default' => '该商户不存在',
            ],
            [
                'key' => 'reply_template',
                'label' => '回复模板',
                'type' => 'template',
                'required' => false,
                'default' => '✅ 本群已绑定商户号：{{merchant_id}}',
            ],
        ];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $chatId = (string) $context->chatId;
        $policy = app(OperatorPolicy::class);
        $routes = app(MerchantRouteService::class);

        // 绑定关系决定了「通知发到哪、谁能在群里点按钮」，默认只给管理员
        if ($context->config('require_admin', true) && ! $policy->isGroupAdmin($chatId, $context->userId, $context->bot)) {
            return $this->fail('只有群管理员可以绑定商户号');
        }

        // 只认命令实参：不带参数就是「查看」，绝不能拿已存数据 / 群配置里兜底的
        // merchant_id 当成本次输入的商户号 —— 那会把「/bd」变成「/bd <商户号>」，
        // 平白去打一次上游还重新绑一遍。
        $merchantId = trim((string) ($context->arg(0) ?: ''));

        // 不带参数：查看当前绑定，只读本地，不请求上游
        if ($merchantId === '') {
            $local = $this->localMerchantId($context, $chatId);

            return $local === ''
                ? $this->reply('本群还没有绑定商户号，用法：/bd <商户号>')
                : $this->reply($this->render(
                    $this->template($context),
                    ['merchant_id' => $local],
                    $context
                ));
        }

        // 带参数：本地已经绑的就是这个号 → 直接用本地记录，不再向上游确认
        if (in_array($merchantId, $routes->forChat($chatId), true)) {
            return $this->reply($this->render(
                $this->template($context),
                ['merchant_id' => $merchantId],
                $context
            ));
        }

        // 先向上游确认这个商户号是否真的存在（用后台选的接口 + 上游）
        // 请求返回里判存在的字段名各家不同，这里按常见的几种兜一下，
        // 具体怎么判交给上游约定：拿到响应后照自己的返回结构改这段即可。
        $check = $this->callEndpoint(
            (string) $context->config('endpoint_code', 'merchant.check'),
            ['merchant_id' => $merchantId],
            $context
        );

        if (isset($check['_error'])) {
            return $this->fail('校验商户失败：' . $check['_error']);
        }

        if (! $this->merchantExists($check)) {
            return $this->fail((string) $context->config('not_exists_tip', '该商户不存在'));
        }

        // 上游：功能配置 > 绑定级 > 机器人级（与 callEndpoint 一致，
        // 保证校验商户和记录路由用的是同一个上游）
        $thirdConfigId = $context->config('upstream_id')
            ?: $context->bind?->third_config_id
            ?: $context->bot?->third_config_id;

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
            return $this->fail($e->getMessage());
        }

        // 同时写一份到功能数据，方便 {{@merchant_id}} 在别的功能里直接引用
        $this->putData($context, ['merchant_id' => $merchantId]);

        // 回写到群配置：后台「群列表 → 配置」里的商户ID 才有东西显示
        // （前端读的就是 group_configs.mid，两张表指的是同一个商户号）
        $this->syncGroupConfig($context, $chatId, $merchantId);

        return $this->reply($this->render(
            $this->template($context),
            ['merchant_id' => $merchantId],
            $context
        ));
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
     * 回复模板
     */
    protected function template(FeatureContext $context): string
    {
        $template = (string) $context->config('reply_template', '');

        return $template !== '' ? $template : '✅ 本群已绑定商户号：{{merchant_id}}';
    }

    /**
     * 从上游响应里判断商户是否存在
     *
     * 各家上游返回的字段名不一样，这里按常见的几种写法兜一下：
     *   {"exists": true} / {"exist": 1} / {"code": 0} / {"success": true}
     * 你的上游是别的结构（比如 {"data": {...}} 或直接返回商户对象），
     * 直接改这个方法里的判断即可，不用动 handle()。
     *
     * 判不出来时返回 true（放行）——宁可让真正存在的商户绑上，也不要因为
     * 上游返回了个没见过的结构就把正常商户拦在外面。
     *
     * @param array<string, mixed> $response
     */
    protected function merchantExists(array $response): bool
    {
        $response = (array) ($response['data'] ?? $response);

        foreach (['exists', 'exist', 'valid', 'found'] as $key) {
            if (array_key_exists($key, $response)) {
                return (bool) $response[$key];
            }
        }

        // 有些上游用 code：0 表示成功
        if (array_key_exists('code', $response)) {
            return (int) $response['code'] === 0;
        }

        if (array_key_exists('success', $response)) {
            return (bool) $response['success'];
        }

        // 返回了内容但没有任何可判字段，视为存在
        return $response !== [] && ! isset($response['_raw']);
    }
}
