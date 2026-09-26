<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Types
    |--------------------------------------------------------------------------
    */

    'payment' => 'Payment Collection',
    'transfer' => 'Transfer',

    /*
    |--------------------------------------------------------------------------
    | Statuses
    |--------------------------------------------------------------------------
    */

    'pending' => 'Pending Payment',
    'processing' => 'Processing',


    'paid' => 'Paid',

    'successful' => 'Successful',
    'failed' => 'Failed',

    'pending_settlement' => 'Pending Settlement',
    'settled' => 'Settled',

    /*
    |--------------------------------------------------------------------------
    | Fields
    |--------------------------------------------------------------------------
    */

    'type' => 'Type: :type',
    'trade_no' => 'Order No: :id',
    'out_trade_no' => 'Merchant Order No: :id',
    'created_at' => 'Order Time: :time',
    'amount' => 'Amount: :amount',
    'total_fees' => 'Service Fee: :amount',
    'currency' => 'Currency: :code',
    'status' => 'Order Status: :status',

    'paid_at' => 'Payment Time: :time',
    'settlement_amount' => 'Settlement Amount: :amount',
    'settlement_status' => 'Settlement Status: :status',
    'settled_at' => 'Settlement Time: :time',

    'payee_name' => 'Payee: :name',
    'payee_account' => 'Payee Account: :account',
    'total_amount' => 'Total: :amount',
    'balance' => 'Balance: :amount',
    'message' => 'Message: :text',

    /*
    |--------------------------------------------------------------------------
    | Others
    |--------------------------------------------------------------------------
    */

    'no_results' => 'No information found related to [:query].',

];
