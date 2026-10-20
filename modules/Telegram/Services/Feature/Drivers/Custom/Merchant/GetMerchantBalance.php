<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\LogMessageService;
use Modules\Telegram\Services\MerchantRouteService;

/**
 * 示例：查询商户余额（自定义功能参考实现）
 *
 * 这个类是「如何扩展一个功能」的完整示范，复制它改一下即可。
 * 新增功能只需要：
 *   1) 复制本文件到同目录，改类名与业务逻辑
 *   2) 执行 php artisan telegram:sync-features
 *   3) 在后台「功能列表」把该功能绑定到群
 *
 * 本例演示的场景：
 *   用户在群里执行 /ye → 取出该群已绑定的 merchant_id（来自 merchant_routes，
 *   也就是「绑定商户号」功能写入的那张表）→ 调三方余额接口 → 用模板渲染回复。
 *   如果该群还没绑定商户号，提示里会按后台实际配置的绑定命令动态显示。
 */
class GetMerchantBalance extends BaseCustomFeature implements EndpointProvider
{
    /**
     * 执行器标识（写入 features.driver）
     */
    public static function key(): string
    {
        return 'custom.get_merchant_balance';
    }

    /**
     * 本功能要用到的上游接口
     *
     * 接口规范跟着「用它的那个类」走，不往 PlatformEndpointRegistry 里堆：
     * 新功能自带自己的接口定义，删掉功能时定义也随之消失，不会留一堆孤儿接口。
     */
    public static function endpoints(): array
    {
        return [
            'merchant.balance' => [
                'name' => '查询商户余额',
                'method' => 'GET',
                'path_template' => 'api/merchant/balance',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true, 'desc' => '商户号', 'map_to' => 'merchantId'],
                ],
                'response_schema' => [
                    'balance' => '余额',
                    'currency' => '币种',
                ],
                'remark' => '按商户号查询余额',
            ],
            'merchant.trade' => [
                'name' => '查询交易明细',
                'method' => 'GET',
                'path_template' => 'api/merchant/trade',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true, 'desc' => '商户号', 'map_to' => 'merchantId'],
                    ['name' => 'trade_no', 'required' => false, 'desc' => '交易号', 'map_to' => 'tradeNo'],
                    ['name' => 'page', 'required' => false, 'desc' => '页码', 'map_to' => 'page', 'default' => 1],
                ],
                'response_schema' => [
                    'total' => '总条数',
                    'list' => '交易列表',
                ],
                'remark' => '按商户号查询交易明细',
            ],
            'merchant.info' => [
                'name' => '查询商户信息',
                'method' => 'GET',
                'path_template' => 'api/merchant/info',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true, 'desc' => '商户号', 'map_to' => 'merchantId'],
                ],
                'response_schema' => [
                    'name' => '商户名称',
                    'status' => '状态',
                ],
                'remark' => '按商户号查询商户基础信息',
            ],
        ];
    }

    public static function label(): string
    {
        return '查询商户余额';
    }

    public static function group(): string
    {
        return '自定义';
    }

    public static function featureName(): string
    {
        return '查询商户余额';
    }

    public static function featureDescription(): string
    {
        return '调用三方余额接口，查询当前群已绑定商户的余额（示例功能）';
    }

    /**
     * 斜杠命令声明：可声明多个
     */
    public static function commands(): array
    {
        return [
            [
                'command'        => 'ye',
                'usage'          => '/ye',
                'description'    => '查询当前群绑定商户的余额',
                'params'         => [],
                'reply_template' => '余额：{{data.balance}} 元',
            ],
        ];
    }

    /**
     * 默认配置（首次注册时写入 features.config）
     *
     * 不给默认三方配置（那是每个用户自己的），但把平台接口与回复模板预设好，
     * 注册后只需在后台选一下接口即可用。
     */
    /**
     * 提示里写死的旧命令：库里 features.config 还存着这份，运行时按它识别并替换
     */
    private const LEGACY_NOT_BOUND_TIP = '本群还没有绑定商户号，请先执行 /bm <商户号>';

    private const DEFAULT_NOT_BOUND_TIP = '本群还没有绑定商户号';

    public static function defaultConfig(): array
    {
        return [
            'upstream_id' => null,
            'endpoint_code' => 'merchant.balance',
            // 不写死命令：命令名在后台随时可改，写死就会提示一个不存在的 /bm
            'not_bound_tip' => self::DEFAULT_NOT_BOUND_TIP,
            'reply_template' => '余额：{{data.balance}} 元',
        ];
    }

    /**
     * 后台可配置项（会自动渲染成表单）
     */
    public static function configSchema(): array
    {
        return [
            [
                // 原来这个字段的 key 也是 endpoint_code，和下面「平台接口」撞了：
                // 两个下拉写同一个 key，互相覆盖，且上游 id 被塞进了 endpoint_code，
                // 于是 BaseCustomFeature::callEndpoint() 里读的 upstream_id 永远取不到
                // （表现就是「选了上游但不生效」）。这里改成正确的 key。
                'key'      => 'upstream_id',
                'label'    => '承接上游',
                'type'     => 'select',
                'source'   => 'third_api_configs',
                'required' => false,
                'hint'     => '留空则依次用「功能绑定上的上游 → 机器人默认上游」',
            ],
            [
                'key'      => 'endpoint_code',
                'label'    => '平台接口',
                'type'     => 'select',
                'source'   => 'platform_endpoints',
                'required' => true,
                'hint'     => '平台统一维护的接口规范；括号内为所选上游实际生效的方法与路径',
            ],
            [
                'key'      => 'not_bound_tip',
                'label'    => '未绑定时的提示',
                'type'     => 'text',
                'required' => false,
                'default'  => '本群还没有绑定商户号',
                'hint'     => '留空用默认文案；可写 {{bind_usage}} 指定命令出现的位置，不写则自动追加在末尾（绑定命令改了这边会跟着变）',
            ],
        ];
    }

    /**
     * 功能逻辑
     */
    public function handle(FeatureContext $context): FeatureResult
    {
        // 1) 取出该群已绑定的商户号
        $merchantId = $this->resolveMerchantId($context);

        if ($merchantId === '') {
            return $this->fail($this->notBoundTip($context));
        }

        // 2) 调平台接口（上游由机器人绑定，各用户不同）
        $data = $this->callEndpoint(
            (string) $context->config('endpoint_code', 'merchant.balance'),
            ['merchant_id' => $merchantId],
            $context
        );

        if (isset($data['_error'])) {
            return $this->fail('查询余额失败：' . $data['_error']);
        }

        // 3) 用命令声明的模板渲染回复
        $template = (string) $context->config('reply_template', '{{_raw}}');

        app(LogMessageService::class)->createLaravelLog('upstream-api-error',$data);
        return $this->reply($this->render($template, $data, $context), $data);
    }

    /**
     * 该群绑定的商户号
     *
     * ⚠️ 不能读 $context->value('merchant_id')：feature_data 是按功能分桶存的，
     * $context->value() 只能读到「本功能」那一桶，而商户号是「绑定商户号」那个功能
     * 用 putData() 写进它自己桶里的 —— 这里读必然是空的，
     * 表现就是「/bd 明明绑成功了，/ye 却说没绑定」。
     * 绑定关系的唯一数据源是 merchant_routes（/bd 写的就是它）。
     */
    protected function resolveMerchantId(FeatureContext $context): string
    {
        $bound = app(MerchantRouteService::class)->forChat((string) $context->chatId);

        if ($bound !== []) {
            return trim((string) reset($bound));
        }

        // 兼容旧数据：本功能自己存过的也算数
        return trim((string) ($context->value('merchant_id') ?? ''));
    }

    /**
     * 未绑定时的提示：命令部分按后台实际配置动态拼，不写死
     */
    protected function notBoundTip(FeatureContext $context): string
    {
        $tip = (string) $context->config('not_bound_tip', self::DEFAULT_NOT_BOUND_TIP);

        // 库里还存着写死 /bm 的旧默认值时，按新默认值处理，不用去后台手工改
        if ($tip === self::LEGACY_NOT_BOUND_TIP) {
            $tip = self::DEFAULT_NOT_BOUND_TIP;
        }

        if ($tip === '') {
            $tip = self::DEFAULT_NOT_BOUND_TIP;
        }

        $usage = $this->bindUsage();

        $tip = str_replace('{{bind_usage}}', $usage, $tip);

        if ($usage !== '' && ! str_contains($tip, $usage)) {
            $tip .= '，请先执行 ' . $usage;
        }

        return $tip;
    }

    /**
     * 「绑定商户号」功能当前实际用的命令（后台可改，写死就一定会错）
     */
    protected function bindUsage(): string
    {
        $command = FeatureCommands::query()
            ->where('enabled', true)
            ->whereHas('feature', fn ($q) => $q->where('driver', BindMerchant::key()))
            ->orderBy('id')
            ->first();

        if (! $command) {
            return '';
        }

        $usage = trim((string) ($command->usage ?? ''));

        return $usage !== '' ? $usage : '/' . $command->command;
    }
}
