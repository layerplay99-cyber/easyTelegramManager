<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Security;

use Catch\Exceptions\FailedException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Modules\Telegram\Models\ThirdApiConfig;

/**
 * 三方入站密钥对（access_key / secret_key）
 *
 * 与「我方 → 上游」方向的 token / public_key / secrept_key 是两个不同方向，
 * 后者保留原样，由 UpstreamWalletGateway 等继续使用。
 *
 * secret_key 明文只在生成时返回一次，落库为 Crypt::encryptString() 密文，
 * 解密依赖 APP_KEY —— APP_KEY 变更会让全部密钥不可解，必须走重新下发。
 */
class UpstreamKeyService
{
    public const STATUS_NONE = 0;
    public const STATUS_ACTIVE = 1;
    public const STATUS_REVOKED = 2;

    /**
     * 生成 / 轮换密钥对，返回唯一一次明文
     *
     * @return array{access_key: string, secret_key: string, key_version: int, key_rotated_at: string}
     */
    public function generate(ThirdApiConfig $config): array
    {
        $accessKey = 'ak_' . bin2hex(random_bytes(16));
        $secretKey = 'sk_' . bin2hex(random_bytes(24));
        $rotatedAt = now();

        $config->forceFill([
            'access_key' => $accessKey,
            'secret_key' => Crypt::encryptString($secretKey),
            'key_status' => self::STATUS_ACTIVE,
            'key_version' => (int) $config->key_version + 1,
            'key_rotated_at' => $rotatedAt,
        ])->save();

        return [
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
            'key_version' => (int) $config->key_version,
            'key_rotated_at' => (string) $rotatedAt,
        ];
    }

    public function revoke(ThirdApiConfig $config): void
    {
        $config->forceFill([
            'key_status' => self::STATUS_REVOKED,
        ])->save();
    }

    /**
     * 取明文密钥。解密失败视为配置故障：拒绝请求并告警，绝不降级放行。
     */
    public function secret(ThirdApiConfig $config): string
    {
        $cipher = (string) ($config->secret_key ?? '');

        if ($cipher === '') {
            throw new FailedException('上游接入密钥未配置');
        }

        try {
            return Crypt::decryptString($cipher);
        } catch (DecryptException $e) {
            Log::critical('[上游密钥] 解密失败，检查 APP_KEY 是否变更', [
                'upstream_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            throw new FailedException('上游接入密钥不可用，请联系平台重新下发');
        }
    }
}
