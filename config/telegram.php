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

];
