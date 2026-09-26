<?php
declare(strict_types=1);

namespace Modules\Telegram\Services;

/**
 * BaseService - 门面服务，向后兼容委托给专门服务
 *
 * 职责委托：
 * - 签名  → SignatureService
 * - 媒体  → TelegramMediaService
 * - 缓存  → RedisCacheService
 * - 消息  → TelegramMessageService
 *
 * 保留此类仅为向后兼容，新代码应直接注入专门服务。
 */
class BaseService
{
    public function __construct(
        public LogMessageService $logMessageService,
        private SignatureService $signatureService,
        private TelegramMediaService $telegramMediaService,
        private RedisCacheService $redisCacheService,
        private TelegramMessageService $telegramMessageService,
    ) {}

    // ===== 签名方法（委托 SignatureService）=====

    public function muchVailSign(array $payload, string $secretKey): bool
    {
        return $this->signatureService->muchVailSign($payload, $secretKey);
    }

    public function muchVailSign1(array $payload, string $secretKey): bool
    {
        return $this->signatureService->muchVailSign1($payload, $secretKey);
    }

    public function vailSign(array $payload, string $secretKey): bool
    {
        return $this->signatureService->vailSign($payload, $secretKey);
    }

    public function getSignature(array $payload): string
    {
        return $this->signatureService->getSignature($payload);
    }

    public function getHookSignature(array $payload): string
    {
        return $this->signatureService->getHookSignature($payload);
    }

    // ===== 媒体方法（委托 TelegramMediaService）=====

    public function putImage($message): string|false
    {
        return $this->telegramMediaService->putImage($message);
    }

    public function getImageUrl($message): string
    {
        return $this->telegramMediaService->getImageUrl($message);
    }

    public function storeImageFromBase64Code(string $base64Image, ?string $customPath = null): array
    {
        return $this->telegramMediaService->storeImageFromBase64Code($base64Image, $customPath);
    }

    // ===== 缓存方法（委托 RedisCacheService）=====

    public function setRedis(int|string $chatId, int $messageId): string
    {
        return $this->redisCacheService->setRedis($chatId, $messageId);
    }

    public function getRedis(string $callbackKey): array
    {
        return $this->redisCacheService->getRedis($callbackKey);
    }

    public function delRedisKey(string $callbackKey): void
    {
        $this->redisCacheService->delRedisKey($callbackKey);
    }

    // ===== 消息方法（委托 TelegramMessageService）=====

    public function successful(array $data): string
    {
        return $this->telegramMessageService->successful($data);
    }

    public function buildTransferMessages($payload, $admins): string
    {
        return $this->telegramMessageService->buildTransferMessages($payload, $admins);
    }

    public function buildTransferPendingMessages($payload, $admins): string
    {
        return $this->telegramMessageService->buildTransferPendingMessages($payload, $admins);
    }

    public function sendMessage($chatId, $text, $messageId = null): void
    {
        $this->telegramMessageService->sendMessage($chatId, $text, $messageId);
    }

    public function sendMessageByToken(string $botToken, int|string $chatId, string $text, array $extra = []): bool
    {
        return $this->telegramMessageService->sendMessageByToken($botToken, $chatId, $text, $extra);
    }

    public function sendPhotoByToken(string $botToken, int|string $chatId, string $photo, string $caption = '', array $extra = []): bool
    {
        return $this->telegramMessageService->sendPhotoByToken($botToken, $chatId, $photo, $caption, $extra);
    }
}
