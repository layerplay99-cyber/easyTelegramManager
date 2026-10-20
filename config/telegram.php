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

    /*
    |--------------------------------------------------------------------------
    | 媒体文件下载超时（秒）
    |--------------------------------------------------------------------------
    | 识图等功能要先把图片从 Bot API 取回来。走本地 Bot API Server 时是内网毫秒级，
    | 走官方则受图片大小影响，给 30 秒。
    |
    */
    'media_download_timeout' => (int) env('TELEGRAM_MEDIA_DOWNLOAD_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | AWS 通用凭证
    |--------------------------------------------------------------------------
    | OCR（Textract）和翻译（Translate）共用一套，放 .env。
    | IAM 用户需要：textract:DetectDocumentText、translate:TranslateText
    |
    */
    'aws' => [
        'region' => env('AWS_REGION', 'us-east-1'),
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 队列拆分
    |--------------------------------------------------------------------------
    | 重活（识图）不能和轻量命令挤在一条队列里：worker 是串行的，
    | 一张图 1~2s，10 张图并发就能把 /bd、/ye 全堵住十几秒。
    |
    | by_media_type：按媒体类型把 update 路由到不同队列，没配的走 default。
    | 起 worker 时 --queue=ocr 只消费 ocr（见 docker-compose.yml 的 queue-ocr）。
    |
    */
    'queues' => [
        'default' => env('TELEGRAM_QUEUE_DEFAULT', 'default'),
        'ocr'     => env('TELEGRAM_QUEUE_OCR', 'ocr'),

        'by_media_type' => [
            'photo' => env('TELEGRAM_QUEUE_OCR', 'ocr'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 群回复双语
    |--------------------------------------------------------------------------
    | 群标记了非默认语种时，回复在默认语种（中文）后面追加一段该语种的译文。
    | 统一在 FeatureExecutor 发出消息前处理，所有功能自动生效，不用改各驱动。
    |
    | 文案来源两级：
    |   1) 功能配置里有 reply_templates[<语种>] → 直接用（业务术语不会被翻错）
    |   2) 没有 → 调 AWS Translate 翻译，结果按「原文 + 目标语种」缓存
    |
    | 翻译按内容缓存：群回复就那几套固定文案，命中率接近 100%，费用可忽略。
    |
    */
    'translate' => [
        'enabled'   => (bool) env('TELEGRAM_TRANSLATE_ENABLED', true),
        // 默认语种即「原文语种」，与 ocr.default_language 保持一致
        'source'    => env('TELEGRAM_TRANSLATE_SOURCE', 'zh'),
        'separator' => "\n",
        'timeout'   => (int) env('AWS_TRANSLATE_TIMEOUT', 10),
        'cache_ttl' => (int) env('TELEGRAM_TRANSLATE_CACHE_TTL', 43200), // 分钟，默认 30 天
    ],

    /*
    |--------------------------------------------------------------------------
    | OCR 识图
    |--------------------------------------------------------------------------
    | 群里发图片 → 取回图片 → OCR → 把识别出的文本拼成一个字符串。
    |
    | 扩展性靠两张表，不要写 if-else：
    |   languages   群可标记的语种，后台下拉直接读这里，加语种只改配置
    |   drivers     OCR 实现。换引擎（比如泰文改 Google Vision）= 加一个类 +
    |               在这里登记一行，业务代码不动
    |   by_language 语种 → 引擎。没配的语种回落 default
    |
    | 目前只有一个 textract 实现，接口和映射表先搭好，等验证过各语种效果再按语种接。
    |
    | 凭证放 .env（通用的一套）：AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_REGION
    |
    */
    'ocr' => [
        'default'     => env('AWS_OCR_DEFAULT', 'textract'),
        'default_language' => 'zh',

        'drivers' => [
            'textract' => \Modules\Telegram\Services\Ocr\Drivers\TextractOcr::class,
        ],

        // 群可标记的语种（后台「群 → 语种」下拉读这里）
        'languages' => [
            'zh' => '中文（默认）',
            'en' => 'English',
            'vi' => 'Tiếng Việt',
            'th' => 'ไทย',
        ],

        // 语种 → 引擎；没配的语种用 default
        'by_language' => [
            'vi' => 'textract',
            'th' => 'textract',
        ],

        'aws' => [
            // 凭证在顶层的 telegram.aws，这里只留 OCR 自己的超时
            'timeout' => (int) env('AWS_OCR_TIMEOUT', 15),
        ],

        // 传给 Textract 的最大图片字节数（同步接口上限 10MB，留点余量）
        'max_bytes' => (int) env('AWS_OCR_MAX_BYTES', 9 * 1024 * 1024),
    ],

];
