<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Types
    |--------------------------------------------------------------------------
    */

    'payment' => 'Thu hộ',
    'transfer' => 'Chuyển tiền',

    /*
    |--------------------------------------------------------------------------
    | Statuses
    |--------------------------------------------------------------------------
    */

    'pending' => 'Chờ thanh toán',
    'processing' => 'Đang xử lý',


    'paid' => 'Đã thanh toán',

    'successful' => 'Thành công',
    'failed' => 'Thất bại',

    'pending_settlement' => 'Chưa quyết toán',
    'settled' => 'Đã quyết toán',

    /*
    |--------------------------------------------------------------------------
    | Fields
    |--------------------------------------------------------------------------
    */

    'type' => 'Loại: :type',
    'trade_no' => 'Số đơn hàng: :id',
    'out_trade_no' => 'Số đơn hàng thương gia: :id',
    'created_at' => 'Thời gian đặt hàng: :time',
    'amount' => 'Số tiền: :amount',
    'total_fees' => 'Phí dịch vụ: :amount',
    'currency' => 'Tiền tệ: :code',
    'status' => 'Trạng thái đơn hàng: :status',

    'paid_at' => 'Thời gian thanh toán: :time',
    'settlement_amount' => 'Số tiền quyết toán: :amount',
    'settlement_status' => 'Trạng thái quyết toán: :status',
    'settled_at' => 'Thời gian quyết toán: :time',

    'payee_name' => 'Người nhận: :name',
    'payee_account' => 'Tài khoản người nhận: :account',
    'total_amount' => 'Tổng cộng: :amount',
    'balance' => 'Số dư: :amount',
    'message' => 'Thông tin: :text',

    /*
    |--------------------------------------------------------------------------
    | Others
    |--------------------------------------------------------------------------
    */

    'no_results' => 'Không tìm thấy thông tin liên quan đến [:query].',

];
