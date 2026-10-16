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
    | 本文件只放本项目实际用到的键；其余键由 SDK 自带配置兜底
    | （vendor/irazasyed/telegram-bot-sdk/src/Laravel/config/telegram.php，
    |  Laravel 的 mergeConfigFrom 会用这里的同名键覆盖它）。
    | 所以没有把整份 SDK 配置发布进来——那只会带来与 SDK 版本的漂移。
    |
    */
    'base_bot_url' => env('TELEGRAM_BASE_BOT_URL', 'https://api.telegram.org/bot'),

    /*
    |--------------------------------------------------------------------------
    | 兜底 Bot Token
    |--------------------------------------------------------------------------
    | 正常不用配：后台每个机器人都有自己的 token（bmbots.api_token），
    | 运行时一律走 BotApiFactory::forBot()，和这个值无关。
    |
    | 唯一需要它的是 NotificationService（充值 / 提现通知）：它按订单发通知，
    | 而订单表上没有 bot_id，只能有一个兜底机器人。不配的后果是钱包类通知
    | 发不出去，且只在日志里记一条 error、不抛异常——所以留空可以，别删这个键。
    |
    | ⚠️ 也不能改成在代码里直接 env('TELEGRAM_BOT_TOKEN')：
    |    config:cache 之后代码里的 env() 对未缓存的键会返回 null，配了也读不到。
    |
    */
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),

];
