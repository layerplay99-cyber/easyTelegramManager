<?php

/** 商户类机器人功能的群内文案（Tiếng Việt） */
return [
    'field_separator' => ': ',

    'fields' => [
        'type' => 'Loại',
        'reference_id' => 'Mã đơn hàng',
        'transaction_id' => 'Mã đơn hàng thương gia',
        'created_time' => 'Thời gian tạo',
        'amount' => 'Số tiền',
        'fees' => 'Phí',
        'total' => 'Tổng cộng',
        'balance' => 'Số dư',
        'currency_code' => 'Tiền tệ',
        'payee_name' => 'Tên người nhận',
        'payee_account' => 'Tài khoản người nhận',
        'status' => 'Trạng thái',
        'paid_time' => 'Thời gian thanh toán',
        'provider_id' => 'Mã nhà cung cấp',
        'settlement_amount' => 'Số tiền đối soát',
        'settled_time' => 'Thời gian đối soát',
    ],

    'type_values' => [
        'Send money' => 'Chi tiền',
        'Receive money' => 'Thu tiền',
    ],

    'status_values' => [
        'Successful' => 'Thành công',
        'Processing' => 'Đang xử lý',
        'Pending' => 'Chờ xử lý',
    ],

    'order' => [
        'title' => '📋 Thông tin đơn hàng',
        'usage' => 'Vui lòng nhập mã đơn hàng. Cú pháp: /cx <mã đơn hàng>',
        'failed' => 'Truy vấn đơn hàng thất bại: ',
        'not_found' => 'Không tìm thấy thông tin cho đơn hàng :id',
    ],

    'warn' => [
        'success' => 'Thiết lập thành công. Ngưỡng cảnh báo số dư hiện tại: :amount',
        'usage' => 'Số tiền không hợp lệ. Cú pháp: /yujing <số tiền>',
        'failed' => 'Thiết lập cảnh báo số dư thất bại: ',
    ],

    'limit_set' => [
        'success' => 'Thiết lập thành công. Đơn chi tiền trên :amount cần xác nhận lần hai',
        'zero' => 'Thiết lập thành công. Đã hủy xác nhận lần hai cho đơn chi tiền',
        'usage' => 'Số tiền không hợp lệ. Cú pháp: /xiane <số tiền> (0 để hủy)',
        'failed' => 'Thiết lập hạn mức chi tiền thất bại: ',
    ],

    'limit_show' => [
        'success' => 'Hạn mức xác nhận lần hai cho đơn chi tiền hiện tại: :amount',
        'failed' => 'Truy vấn hạn mức chi tiền thất bại: ',
    ],

    'common' => [
        'not_bound' => 'Nhóm này chưa được gắn mã thương gia',
        'not_bound_suffix' => '. Vui lòng chạy :usage trước',
        'yes' => 'Có',
        'no' => 'Không',
    ],
];
