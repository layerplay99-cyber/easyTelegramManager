<?php
declare(strict_types=1);

namespace Modules\Telegram\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Redis 缓存服务 - 回调键值管理
 */
class RedisCacheService
{
    /**
     * 设置 Redis 缓存
     */
    public function setRedis(int|string $chatId, int $messageId): string
    {
        $key = $chatId . time() . rand(0, 10000);
        $value = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ];
        Redis::set($key, json_encode($value));

        return $key;
    }

    /**
     * 获取 Redis 缓存
     */
    public function getRedis(string $callbackKey): array
    {
        $data = Redis::get($callbackKey);
        return $data ? json_decode($data, true) : [];
    }

    /**
     * 删除 Redis 缓存
     */
    public function delRedisKey(string $callbackKey): void
    {
        Redis::del($callbackKey);
    }
}
