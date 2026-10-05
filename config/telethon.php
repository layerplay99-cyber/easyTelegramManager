<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telethon 服务（Python）
    |--------------------------------------------------------------------------
    | 原 MadelineProto（PHP）的替代实现：所有 MTProto 操作都通过 HTTP 交给
    | Python 的 Telethon 服务执行，本项目只保留业务编排与落库。
    */
    'base_url' => env('TELETHON_BASE_URL', 'http://telegram-py:8081'),

    // 单次请求超时（秒）
    'timeout' => (int) env('TELETHON_TIMEOUT', 120),

    /*
    | 事件回调：Python 侧把「新消息表情 / 群成员变更」POST 回本项目，
    | 由 TelethonEventController 转成既有事件与队列任务，链路与原 SessionEventHandler 一致。
    | 必须与 Python 服务的 CALLBACK_TOKEN 一致。
    */
    'callback_token' => env('TELETHON_CALLBACK_TOKEN', ''),

    // 共享媒体目录（Python 下载头像的落地位置，需与 PHP 容器同一卷）
    'media_path' => env('TELETHON_MEDIA_PATH', '/data/media'),
];
