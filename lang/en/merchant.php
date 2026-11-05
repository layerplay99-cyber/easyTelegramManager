<?php

/** 商户类机器人功能的群内文案（English） */
return [
    'field_separator' => ': ',

    'fields' => [
        'type' => 'Type',
        'reference_id' => 'Order ID',
        'transaction_id' => 'Merchant Order ID',
        'created_time' => 'Created At',
        'amount' => 'Amount',
        'fees' => 'Fee',
        'total' => 'Total',
        'balance' => 'Balance',
        'currency_code' => 'Currency',
        'payee_name' => 'Payee Name',
        'payee_account' => 'Payee Account',
        'status' => 'Status',
        'paid_time' => 'Paid At',
        'provider_id' => 'Provider ID',
        'settlement_amount' => 'Settlement Amount',
        'settled_time' => 'Settled At',
    ],

    'type_values' => [
        'Send money' => 'Payout',
        'Receive money' => 'Collection',
    ],

    'status_values' => [
        'Successful' => 'Successful',
        'Processing' => 'Processing',
        'Pending' => 'Pending',
    ],

    'order' => [
        'title' => '📋 Order Details',
        'usage' => 'Please provide an order ID. Usage: /cx <order ID>',
        'failed' => 'Failed to query order: ',
        'not_found' => 'No information found for order :id',
    ],

    'warn' => [
        'success' => 'Success. Current low balance alert threshold: :amount',
        'usage' => 'Invalid amount. Usage: /yujing <amount>',
        'failed' => 'Failed to set low balance alert: ',
    ],

    'limit_set' => [
        'success' => 'Success. Payout orders above :amount require secondary confirmation',
        'zero' => 'Success. Secondary confirmation for payout orders is disabled',
        'usage' => 'Invalid amount. Usage: /xiane <amount> (0 to disable)',
        'failed' => 'Failed to set payout limit: ',
    ],

    'limit_show' => [
        'success' => 'Current payout secondary confirmation limit: :amount',
        'failed' => 'Failed to query payout limit: ',
    ],

    'common' => [
        'not_bound' => 'No merchant ID is bound to this group',
        'not_bound_suffix' => '. Please run :usage first',
        'yes' => 'Yes',
        'no' => 'No',
    ],
];
