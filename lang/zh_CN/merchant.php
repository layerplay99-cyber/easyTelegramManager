<?php

/**
 * 商户类机器人功能的群内文案（中文）
 *
 * 语种按「群设置 → 语种配置」走，zh 对应本文件（zh_CN）。
 * fields 的 key 必须与上游返回的字段名一致；上游返回了未收录的字段时，
 * QueryOrder 会直接用原始字段名当标签，不会丢数据。
 */
return [
    'field_separator' => '：',

    'fields' => [
        'type' => '类型',
        'reference_id' => '订单号',
        'transaction_id' => '商户订单号',
        'created_time' => '创建时间',
        'amount' => '金额',
        'fees' => '手续费',
        'total' => '合计',
        'balance' => '余额',
        'currency_code' => '币种',
        'payee_name' => '收款人姓名',
        'payee_account' => '收款人账号',
        'status' => '状态',
        'paid_time' => '付款时间',
        'provider_id' => '提供商ID',
        'settlement_amount' => '结算金额',
        'settled_time' => '结算时间',
    ],

    'type_values' => [
        'Send money' => '代付',
        'Receive money' => '代收',
    ],

    'status_values' => [
        'Successful' => '成功',
        'Processing' => '处理中',
        'Pending' => '待处理',
    ],

    'order' => [
        'title' => '📋 订单信息',
        'usage' => '请提供订单ID，用法：/cx <订单ID>',
        'failed' => '查询订单失败：',
        'not_found' => '未查询到订单 :id 的信息',
    ],

    'warn' => [
        'success' => '设置成功，当前余额预警线为：:amount',
        'usage' => '金额不正确，用法：/yujing <金额>',
        'failed' => '设置余额预警失败：',
    ],

    'limit_set' => [
        'success' => '设置成功，当前商户代付订单金额大于：:amount 时需要二次确认',
        'zero' => '设置成功，当前商户代付取消二次确认',
        'usage' => '金额不正确，用法：/xiane <金额>（0 表示取消二次确认）',
        'failed' => '设置代付限额失败：',
    ],

    'limit_show' => [
        'success' => '当前商户代付订单二次确认限制金额为：:amount',
        'failed' => '查询代付限额失败：',
    ],

    'common' => [
        'not_bound' => '本群还没有绑定商户号',
        'not_bound_suffix' => '，请先执行 :usage',
        'yes' => '是',
        'no' => '否',
    ],
];
