<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bot API 基础地址
    |--------------------------------------------------------------------------
    | 默认走 Telegram 官方 https://api.telegram.org/bot。
    |
    | 部署了本地 Telegram Bot API Server（TDLib）时改成它的地址，例如：
    |   http://telegram-bot-api:8083/bot   （Docker / stack 网络）
    |   http://127.0.0.1:8083/bot          （宿主机同机）
    |
    | 注意必须写到 /bot 这一级：SDK 会在后面自己拼 <token>/<method>，
    | 文件下载地址 /file/bot<token>/<path> 也从同一个 base 推导。
    | 收益与部署方式见 docs/telegram-bot-api-server.md。
    |
    | 本文件只放这一个键；其余键由 SDK 自带配置兜底
    | （vendor/irazasyed/telegram-bot-sdk/src/Laravel/config/telegram.php，
    |  Laravel 的 mergeConfigFrom 会用本文件的同名键覆盖它）。
    | 所以没有把整份 SDK 配置发布进来——那只会带来与 SDK 版本的漂移。
    |
    | ⚠️ 这里不放 bot_token：本项目是多 bot 多商户，没有全局兜底 bot，
    |    每个机器人的 token 都在 bmbots.api_token 上，由调用方按业务场景确定用哪个。
    |
    */
    'base_bot_url' => env('TELEGRAM_BASE_BOT_URL', 'https://api.telegram.org/bot'),

    /*
    |--------------------------------------------------------------------------
    | 出站 HTTP 超时（秒）
    |--------------------------------------------------------------------------
    | 并发下最危险的不是「算不动」，而是「进程在干等」。
    | Guzzle 默认没有连接超时（connect_timeout=0 表示无限等待），本项目原来也只设了
    | 总超时。一旦对端的 DNS / TCP / TLS 握手卡住，当前进程会空等到总超时才报错，
    | 队列 worker 就被整条占住几十秒；并发一上来，等待被排队放大，就是雪崩。
    |
    |   connect_timeout  只管建连，必须远小于 timeout。建连失败要立刻失败并重试，
    |                    不能把 worker 的时间耗在一次没希望的连接上。
    |   timeout          未显式指定时的默认总超时。
    |   max_timeout      硬上限。后台 / 上游把超时配得再大也不会超过它 ——
    |                    宁可快速失败后按 order_no 对账，也不要让 worker 干等 30 秒。
    |
    | 由 Providers/HttpClientServiceProvider 把 connect_timeout 注入为全局默认值，
    | 任何调用点显式传的 timeout 仍然优先。
    |
    */
    'http_client' => [
        'connect_timeout' => (int) env('TELEGRAM_HTTP_CONNECT_TIMEOUT', 3),
        'timeout'         => (int) env('TELEGRAM_HTTP_TIMEOUT', 10),
        'max_timeout'     => (int) env('TELEGRAM_HTTP_MAX_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook 是否记录完整 update
    |--------------------------------------------------------------------------
    | 关闭时只记 update_id。全量 update（含 headers、body）每请求都要序列化并写一份
    | 大 payload 到磁盘，是 webhook 主链路里唯一的同步 IO，高并发下会把磁盘拖成串行
    | 瓶颈（表现为：CPU 不满、无慢 SQL、并发越大越慢）。
    | 排查问题时临时置为 true 即可。
    |
    */
    'log_webhook_update' => (bool) env('TELEGRAM_LOG_WEBHOOK_UPDATE', false),

];
