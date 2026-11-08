<?php

declare(strict_types=1);

/**
 * 三方推送回调（/api/hooks/{token}）的全局默认
 *
 * 入站验签已统一走平台头签名规范（UpstreamSignature，access_key/secret_key），
 * 不再有按 hook 单独配的 body-sign 密钥，因此这里不再保留 secret/sign_* 配置。
 */
return [
    /**
     * 交互会话默认有效期（秒）——按钮多久后失效
     */
    'session_ttl' => (int) env('TELEGRAM_INTERACTIVE_TTL', 86400),
];
