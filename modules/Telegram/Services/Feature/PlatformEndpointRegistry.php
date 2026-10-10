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

            // ---------- 钱包：充值 / 提现 / 余额 ----------
            // 接入支付上游必须的 4 个接口：充值拉单、充值查单、提现拉单、提现查单。
            // 每个上游可在「上游接口配置」里单独覆盖 path/method/headers/query，
            // 即所谓「拉单 API / 查单 API」按上游各自配置；key、密钥、通道ID 在上游实例上。
            'wallet.recharge.create' => [
                'code' => 'wallet.recharge.create',
                'name' => '充值拉单',
                'method' => 'POST',
                'path_template' => 'api/wallet/recharge/create',
                'params_schema' => [
                    ['name' => 'order_no', 'required' => true, 'desc' => '平台订单号', 'map_to' => 'orderNo'],
                    ['name' => 'amount', 'required' => true, 'desc' => '金额', 'map_to' => 'amount'],
                    ['name' => 'currency', 'required' => true, 'desc' => '币种', 'map_to' => 'currency'],
                    ['name' => 'channel_id', 'required' => false, 'desc' => '通道ID', 'map_to' => 'channelId'],
                    ['name' => 'notify_url', 'required' => false, 'desc' => '回调地址', 'map_to' => 'notifyUrl'],
                ],
                'response_schema' => [
                    'order_no' => '平台订单号',
                    'third_order_no' => '上游订单号',
                    'pay_url' => '支付链接',
                    'status' => '状态',
                ],
                'remark' => '向上游发起充值，返回支付链接',
            ],
            'wallet.recharge.query' => [
                'code' => 'wallet.recharge.query',
                'name' => '充值查单',
                'method' => 'GET',
                'path_template' => 'api/wallet/recharge/query',
                'params_schema' => [
                    ['name' => 'order_no', 'required' => true, 'desc' => '平台订单号', 'map_to' => 'orderNo'],
                    ['name' => 'third_order_no', 'required' => false, 'desc' => '上游订单号', 'map_to' => 'thirdOrderNo'],
                ],
                'response_schema' => [
                    'order_no' => '平台订单号',
                    'status' => '状态：pending/paid/failed',
                    'amount' => '实际到账金额',
                ],
                'remark' => '查询充值订单在上游的最终状态',
            ],
            'wallet.withdraw.create' => [
                'code' => 'wallet.withdraw.create',
                'name' => '提现拉单',
                'method' => 'POST',
                'path_template' => 'api/wallet/withdraw/create',
                'params_schema' => [
                    ['name' => 'order_no', 'required' => true, 'desc' => '平台订单号', 'map_to' => 'orderNo'],
                    ['name' => 'amount', 'required' => true, 'desc' => '金额', 'map_to' => 'amount'],
                    ['name' => 'currency', 'required' => true, 'desc' => '币种', 'map_to' => 'currency'],
                    ['name' => 'channel_id', 'required' => false, 'desc' => '通道ID', 'map_to' => 'channelId'],
                    ['name' => 'account', 'required' => false, 'desc' => '收款账号', 'map_to' => 'account'],
                    ['name' => 'notify_url', 'required' => false, 'desc' => '回调地址', 'map_to' => 'notifyUrl'],
                ],
                'response_schema' => [
                    'order_no' => '平台订单号',
                    'third_order_no' => '上游订单号',
                    'status' => '状态',
                ],
                'remark' => '向上游发起提现（代付）',
            ],
            'wallet.withdraw.query' => [
                'code' => 'wallet.withdraw.query',
                'name' => '提现查单',
                'method' => 'GET',
                'path_template' => 'api/wallet/withdraw/query',
                'params_schema' => [
                    ['name' => 'order_no', 'required' => true, 'desc' => '平台订单号', 'map_to' => 'orderNo'],
                    ['name' => 'third_order_no', 'required' => false, 'desc' => '上游订单号', 'map_to' => 'thirdOrderNo'],
                ],
                'response_schema' => [
                    'order_no' => '平台订单号',
                    'status' => '状态：pending/success/failed',
                ],
                'remark' => '查询提现订单在上游的最终状态',
            ],
            'wallet.balance' => [
                'code' => 'wallet.balance',
                'name' => '查询上游余额',
                'method' => 'GET',
                'path_template' => 'api/wallet/balance',
                'params_schema' => [
                    ['name' => 'currency', 'required' => false, 'desc' => '币种', 'map_to' => 'currency'],
                ],
                'response_schema' => [
                    'balance' => '余额',
                    'currency' => '币种',
                ],
                'remark' => '查询上游商户可用余额（平台侧余额以本地钱包为准）',
            ],
            'merchant.trade.submit' => [
                'code' => 'merchant.trade.submit',
                'name' => '提交交易处理结果',
                'method' => 'POST',
                'path_template' => 'api/merchant/trade/submit',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true, 'desc' => '商户号', 'map_to' => 'merchantId'],
                    ['name' => 'trade_no', 'required' => true, 'desc' => '上游单据号', 'map_to' => 'tradeNo'],
                    ['name' => 'action', 'required' => true, 'desc' => '动作码，对应按钮的 act', 'map_to' => 'action'],
                    ['name' => 'operator_id', 'required' => false, 'desc' => '操作人 Telegram ID', 'map_to' => 'operatorId'],
                ],
                'response_schema' => [
                    'success' => '是否成功',
                    'message' => '上游返回说明',
                ],
                'remark' => '群里点了交易按钮之后，把结果提交给上游（上游验签通过才执行）',
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