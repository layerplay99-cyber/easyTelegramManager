<?php

declare(strict_types=1);

/**
 * 三方推送回调（/api/hooks/{token}）的全局默认
 *
 * 单个 hook 可在后台覆写（feature_hooks.secret / sign_algo / sign_field）；
 * 这里配的是「没单独配时的兜底值」。
 */
return [
    /**
     * 全局验签密钥（env 里约定好的那把）
     *
     * 留空 = 不验签（兼容已有接入方）。新接入一律要求配。
     */
    'secret' => env('TELEGRAM_HOOK_SECRET', ''),

    /**
     * 签名算法：hmac_sha256（推荐） / md5（部分老上游只有 md5）
     */
    'sign_algo' => env('TELEGRAM_HOOK_SIGN_ALGO', 'hmac_sha256'),

    /**
     * 签名字段名
     */
    'sign_field' => env('TELEGRAM_HOOK_SIGN_FIELD', 'sign'),

    /**
     * timestamp 允许偏差（秒），防重放
     */
    'sign_ttl' => (int) env('TELEGRAM_HOOK_SIGN_TTL', 300),

    /**
     * 交互会话默认有效期（秒）——按钮多久后失效
     */
    'session_ttl' => (int) env('TELEGRAM_INTERACTIVE_TTL', 86400),
];
