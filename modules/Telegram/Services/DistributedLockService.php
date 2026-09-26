<?php
declare(strict_types=1);

namespace Modules\Telegram\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Lock;

class DistributedLockService
{
    /**
     * 尝试获取分布式锁并执行回调
     *
     * @param string $key 锁的唯一键
     * @param callable $callback 获取锁后执行的回调函数
     * @param int $seconds 锁的超时时间（秒）
     * @param callable|null $failCallback 获取锁失败时的回调函数
     * @return mixed 回调函数的返回值
     */
    public function lock(string $key, callable $callback, int $seconds = 10, ?callable $failCallback = null): mixed
    {
        $lock = Cache::lock($key, $seconds);

        if (!$lock->get()) {
            if ($failCallback) {
                return $failCallback();
            }
            return null;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * 获取锁
     *
     * @param string $key 锁的唯一键
     * @param int $seconds 锁的超时时间（秒）
     * @return Lock|null 返回锁对象，如果获取失败返回 null
     */
    public function acquire(string $key, int $seconds = 10): ?Lock
    {
        $lock = Cache::lock($key, $seconds);

        if ($lock->get()) {
            return $lock;
        }

        return null;
    }

    /**
     * 释放锁
     *
     * @param Lock $lock 锁对象
     * @return bool
     */
    public function release(Lock $lock): bool
    {
        return $lock->release();
    }

    /**
     * 阻塞获取锁（会等待直到获取成功）
     *
     * @param string $key 锁的唯一键
     * @param callable $callback 获取锁后执行的回调函数
     * @param int $seconds 锁的超时时间（秒）
     * @param int $waitSeconds 最多等待多少秒
     * @return mixed
     * @throws \Exception
     */
    public function block(string $key, callable $callback, int $seconds = 10, int $waitSeconds = 10)
    {
        $lock = Cache::lock($key, $seconds);

        try {
            $lock->block($waitSeconds);
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * 检查锁是否存在
     *
     * @param string $key 锁的唯一键
     * @return bool
     */
    public function exists(string $key): bool
    {
        return Cache::has($key);
    }

    /**
     * 强制释放锁
     *
     * @param string $key 锁的唯一键
     * @return bool
     */
    public function forceRelease(string $key): bool
    {
        return Cache::forget($key);
    }

    /**
     * 生成 Telegram Update 锁的 key
     *
     * @param int $updateId Update ID
     * @param int|string|null $chatId Chat ID
     * @param int|null $botId Bot ID（备用）
     * @return string
     */
    public function makeTelegramUpdateLockKey(int $updateId, int|string $chatId = null, ?int $botId = null): string
    {
        $suffix = $chatId ?? ($botId ?? 'unknown');
        return "telegram:update:lock:{$updateId}:{$suffix}";
    }

    /**
     * 生成通用的锁 key
     *
     * @param string $prefix 前缀
     * @param array $params 参数数组
     * @return string
     */
    public function makeLockKey(string $prefix, array $params = []): string
    {
        if (empty($params)) {
            return $prefix;
        }

        $suffix = implode(':', array_map(function ($value) {
            return is_array($value) ? md5(json_encode($value)) : $value;
        }, $params));

        return "{$prefix}:{$suffix}";
    }
}
