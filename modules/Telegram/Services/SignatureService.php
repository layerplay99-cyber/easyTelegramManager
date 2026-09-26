<?php
declare(strict_types=1);

namespace Modules\Telegram\Services;

/**
 * 签名服务 - 统一管理 3 种签名规则
 *
 * 签名规则：
 * 1. hook 通道（getHookSignature/muchVailSign）：filterNull=true,  jsonEncode=true
 * 2. dypay 通道（getSignature/muchVailSign1）：  filterNull=false, jsonEncode=true
 * 3. 回调验签（vailSign）：                       filterNull=true,  jsonEncode=false
 */
class SignatureService
{
    private const HASH_ALGO = 'sha256';
    private const SIGNATURE_FIELD = 'signature';
    private const SIGNATURE_IGNORE_FIELDS = ['signature'];
    private const LOG_FILE_LARAVEL = 'laravel';

    public function __construct(
        private LogMessageService $logMessageService,
    ) {}

    /**
     * 校验签名（方法1 - 过滤空值和 null）
     */
    public function muchVailSign(array $payload, string $secretKey): bool
    {
        return $this->verifySignature($payload, $secretKey, filterNull: true, withLog: false);
    }

    /**
     * 校验签名（方法2 - 仅过滤空字符串）
     */
    public function muchVailSign1(array $payload, string $secretKey): bool
    {
        return $this->verifySignature($payload, $secretKey, filterNull: false, withLog: true, logChannel: 'muchVailSign');
    }

    /**
     * 校验签名（带日志，不做 JSON 处理）
     */
    public function vailSign(array $payload, string $secretKey): bool
    {
        return $this->verifySignature($payload, $secretKey, filterNull: true, withLog: true, logChannel: 'handle_callback_work', jsonEncode: false);
    }

    /**
     * 签名校验统一实现
     */
    private function verifySignature(
        array $payload,
        string $secretKey,
        bool $filterNull = true,
        bool $withLog = false,
        string $logChannel = '',
        bool $jsonEncode = true,
    ): bool {
        if (!isset($payload[self::SIGNATURE_FIELD])) {
            return false;
        }

        $signature = $payload[self::SIGNATURE_FIELD];
        $stringToSign = $this->buildStringToSignByArrays($payload, $filterNull, $jsonEncode);
        $vailSignature = hash_hmac(self::HASH_ALGO, $stringToSign, $secretKey);

        if ($withLog) {
            $this->logMessageService->createLaravelLog($logChannel, [
                'payload' => $payload,
                'stringToSign' => $stringToSign,
                'vailSignature' => $vailSignature,
                'signature' => $signature,
            ]);
        }

        return hash_equals($vailSignature, $signature);
    }

    /**
     * 获取签名（dypay 通道 - 仅过滤空字符串）
     */
    public function getSignature(array $payload): string
    {
        $secretKey = config('dypay.third_tele_secret_key') ?? '';
        if (empty($secretKey)) {
            $this->logMessageService->createLaravelLog(
                self::LOG_FILE_LARAVEL,
                ['error' => 'getSignature error: secret key is empty'],
                'Secret key is empty',
                'error'
            );
            return '';
        }

        $stringToSign = $this->buildStringToSignByArrays($payload, filterNull: false);
        return hash_hmac(self::HASH_ALGO, $stringToSign, $secretKey);
    }

    /**
     * 获取 HOOK 签名（hook 通道 - 过滤空值和 null）
     */
    public function getHookSignature(array $payload): string
    {
        $secretKey = config('hook.dy.hook_secret_key') ?? '';
        if (empty($secretKey)) {
            $this->logMessageService->createLaravelLog(
                self::LOG_FILE_LARAVEL,
                ['error' => 'getHookSignature error: secret key is empty'],
                'Hook secret key is empty',
                'error'
            );
            return '';
        }

        $stringToSign = $this->buildStringToSignByArrays($payload, filterNull: true);
        return hash_hmac(self::HASH_ALGO, $stringToSign, $secretKey);
    }

    /**
     * 构建签名字符串（统一实现）
     *
     * @param bool $filterNull 是否过滤 null 值（不同对接方规则不同）
     * @param bool $jsonEncode 是否对 array/object 做 JSON 编码
     */
    private function buildStringToSignByArrays(array $payload, bool $filterNull = true, bool $jsonEncode = true): string
    {
        $collection = collect($payload)
            ->reject(fn ($value, $key) => in_array($key, self::SIGNATURE_IGNORE_FIELDS) || $value === '');

        if ($filterNull) {
            $collection = $collection->filter(fn ($value) => $value !== null);
        }

        return $collection
            ->sortKeys()
            ->map(function ($value, string $key) use ($jsonEncode) {
                if ($jsonEncode && (is_array($value) || is_object($value))) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                return "{$key}={$value}";
            })
            ->join('&');
    }
}
