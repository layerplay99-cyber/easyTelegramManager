<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;

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
 *   用户在群里执行 /ye → 取出该群已绑定的 merchant_id（由「绑定商户号」功能存入
 *   feature_data 表）→ 调三方余额接口 → 用模板渲染回复。
 *   如果该群还没绑定商户号，会提示用户先执行 /bm。
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
    public static function defaultConfig(): array
    {
        return [
      'endpoint_code' => 'merchant.balance',
  'not_bound_tip' => '本群还没有绑定商户号，请先执行 /bm <商户号>',
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
                'key'      => 'endpoint_code',
                'label'    => '三方配置',
                'type'     => 'select',
                'source'   => 'third_api_configs',
                'required' => true,
                'hint'     => '在「三方配置」里维护地址与token',
            ],
            [
                'key'      => 'endpoint_code',
                'label'    => '平台接口',
                'type'     => 'select',
                'source'   => 'platform_endpoints',
                'required' => true,
                'hint'     => '平台统一维护的接口规范',
            ],
            [
                'key'      => 'not_bound_tip',
                'label'    => '未绑定时的提示',
                'type'     => 'text',
                'required' => false,
                'default'  => '本群还没有绑定商户号，请先执行 /bm <商户号>',
            ],
        ];
    }

    /**
     * 功能逻辑
     */
    public function handle(FeatureContext $context): FeatureResult
    {
        // 1) 取出该群已绑定的商户号（由「绑定商户号」功能写入 feature_data）
        $merchantId = $context->value('merchant_id');

        if (! $merchantId) {
            $tip = (string) $context->config(
                'not_bound_tip',
                '本群还没有绑定商户号，请先执行 /bm <商户号>'
            );

            return $this->fail($tip);
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

        return $this->reply($this->render($template, $data, $context), $data);
    }
}