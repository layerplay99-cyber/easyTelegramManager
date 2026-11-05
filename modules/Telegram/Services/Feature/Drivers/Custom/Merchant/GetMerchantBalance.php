<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\LogMessageService;
use Modules\Telegram\Services\Translate\LocalizedReply;

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
 *   也就是「绑定商户号」功能写入的那张表）→ 调三方余额接口 → 按群语种回复。
 *   如果该群还没绑定商户号，提示里会按后台实际配置的绑定命令动态显示。
 *
 * 商户号解析 / 未绑定提示 / 绑定命令 / 多语文案全部走 LocalizedReply trait，
 * 与其它 6 个商户功能共用，不在这里重复实现。
 */
class GetMerchantBalance extends BaseCustomFeature implements EndpointProvider
{
    use LocalizedReply;

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
        return '调用三方余额接口，查询当前群已绑定商户的余额，按群语种回复（示例功能）';
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
                'description'    => '查询当前群绑定商户的余额（按群语种回复）',
                'params'         => [],
            ],
        ];
    }

    /**
     * 默认配置（首次注册时写入 features.config）
     *
     * 回复模板/提示留空走语言包；库里存着的旧中文默认值由 customText() 识别。
     */
    public static function defaultConfig(): array
    {
        return [
            'upstream_id' => null,
            'endpoint_code' => 'merchant.balance',
            'not_bound_tip' => '',
            'reply_template' => '',
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
                'default'  => '',
                'hint'     => '留空按群语种自动翻译；填写则固定使用该文案。可写 {{bind_usage}} 指定命令位置',
            ],
            [
                'key'      => 'reply_template',
                'label'    => '回复模板',
                'type'     => 'template',
                'required' => false,
                'default'  => '',
                'hint'     => '留空按群语种自动翻译；填写则固定使用该文案。可用变量 {{data.balance}} 等上游字段',
            ],
        ];
    }

    /**
     * 功能逻辑
     */
    public function handle(FeatureContext $context): FeatureResult
    {
        // 1) 取出该群已绑定的商户号（trait 提供，与 /bd 写的是同一张表）
        $merchantId = $this->resolveMerchantId($context);

        if ($merchantId === '') {
            return $this->localizedFail($this->notBoundTip($context));
        }

        // 2) 调平台接口（上游由机器人绑定，各用户不同）
        $data = $this->callEndpoint(
            (string) $context->config('endpoint_code', 'merchant.balance'),
            ['merchant_id' => $merchantId],
            $context
        );

        if (isset($data['_error'])) {
            return $this->localizedFail($this->t($context, 'balance.failed') . $data['_error']);
        }

        app(LogMessageService::class)->createLaravelLog('upstream-api-error', $data);

        // 3) 上游「查询余额」与「设置余额预警」返回的是同一个钱包结构：
        //    {"data":{"id":158,"available_funds":"978479062.00","locked_funds":"0.00",
        //             "warn_funds":null,"currency":{"code":"VND","symbol":"₫"}}}
        //    没有 balance 字段 —— 之前取 data.balance 得到 null，
        //    群里就显示「余额：- VND」（币种能取到是因为 currency.code 存在）。
        $wallet = data_get($data, 'data');
        $wallet = is_array($wallet) ? $wallet : $data;

        $balance = $wallet['available_funds']
            ?? $wallet['balance']
            ?? $wallet['available_balance']
            ?? null;

        $currency = $wallet['currency'] ?? null;

        if (is_array($currency)) {
            $currency = $currency['code'] ?? $currency['symbol'] ?? null;
        }

        // 4) 后台填了模板就按模板渲染（自定义内容不翻译），否则按群语种出文案
        $custom = $this->customText($context, 'reply_template');

        if ($custom !== '') {
            // 把各上游的真实余额字段映射到标准变量 data.balance，
            // 让后台按平台标准文档写的模板（{{data.balance}}）在任何上游下都能取到值
            $renderData = $data;
            data_set($renderData, 'data.balance', $balance);

            return $this->localizedReply($this->render($custom, $renderData, $context), $data);
        }

        $text = trim($this->t($context, 'balance.value', [
            'balance' => is_scalar($balance) ? (string) $balance : '-',
            'currency' => is_scalar($currency) ? (string) $currency : '',
        ]));

        return $this->localizedReply($text, $data);
    }
}
