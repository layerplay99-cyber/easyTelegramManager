<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

/**
 * 平台接口规范（接入标准）
 *
 * 这是平台对外发布的「接入标准」：上游厂商按这里的定义实现接口即可接入，
 * 平台侧无需任何代码改动。新增一类接口 = 在这里加一条定义 + 跑一次同步命令。
 *
 * 设计要点（三层解耦）：
 *   features 功能            全局唯一，不含任何上游信息，只引用 code
 *   endpoints 接口规范       平台维护，含统一入参/出参
 *   thirdapi_config 上游实例 每个用户/机器人一份，只有 base_url + token 不同
 *
 * 入参 params_schema 声明「平台统一参数」，出参 response_schema 声明「统一返回字段」，
 * 不同上游的路径可以不同（path_template），但参数语义一致。
 */
class PlatformEndpointRegistry
{
    /**
     * 平台标准接口定义
     *
     * code            平台唯一标识，功能按它引用
     * name            中文名
     * method          HTTP 方法
     * path_template   路径模板（可用 {param} 占位，会按 params 取值替换）
     * params_schema   统一入参规范
     * response_schema 统一出参规范
     * remark          说明
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'merchant.balance' => [
                'code' => 'merchant.balance',
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
                'code' => 'merchant.trade',
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
                'code' => 'merchant.info',
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

    /**
     * 取单个定义
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $code): ?array
    {
        return self::definitions()[$code] ?? null;
    }

    /**
     * 同步到数据库（幂等：按 code upsert，不覆盖后台改过的启用状态）
     *
     * @return array<int, string> 同步的 code 列表
     */
    public static function sync(): array
    {
        $codes = [];

        foreach (self::definitions() as $definition) {
            \Modules\Telegram\Models\ThirdApiEndpoints::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'method' => $definition['method'],
                    'path_template' => $definition['path_template'],
                    'params_schema' => $definition['params_schema'],
                    'response_schema' => $definition['response_schema'],
                    'headers' => null,
                    'query' => null,
                    'timeout' => 30,
                    'enabled' => true,
                    'remark' => $definition['remark'] ?? null,
                ]
            );

            $codes[] = $definition['code'];
        }

        return $codes;
    }

    /**
     * 后台下拉选项：code + 名称 + 方法路径
     *
     * @return array<int, array<string, mixed>>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::definitions() as $definition) {
            $options[] = [
                'value' => $definition['code'],
                'label' => sprintf('%s（%s %s）', $definition['name'], $definition['method'], $definition['path_template']),
            ];
        }

        return $options;
    }
}