<?php

declare(strict_types=1);

namespace Modules\Telegram\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Models\MerchantRoute;

/**
 * 商户号路由：商户号 → (机器人, 群, 上游)
 *
 * 取代旧实现的 Cache::forever('mid', chat_id)：
 *   · 旧写法单机单商户，多商户多 bot 下互相覆盖，清缓存即丢，也无法审计；
 *   · 现在一个商户号一行，唯一约束保证不会被两个群抢绑。
 *
 * 读是高频路径（每条上游回调都要查），这里加一层短缓存，
 * 绑定/解绑时主动清除——缓存只是加速，库始终是唯一数据源。
 */
class MerchantRouteService
{
    private const CACHE_PREFIX = 'merchant:route:';

    private const CACHE_TTL = 600;

    /**
     * 绑定（同一商户号重复绑定视为改绑）
     *
     * @throws \RuntimeException 该商户号已被其它群绑定时
     */
    public function bind(
        int $botId,
        string $chatId,
        string $merchantId,
        ?int $thirdConfigId = null,
        ?int $featureId = null,
        bool $force = true
    ): MerchantRoute {
        $merchantId = trim($merchantId);

        $route = MerchantRoute::query()->where('merchant_id', $merchantId)->first();

        if ($route) {
            $owned = (string) $route->chat_id === (string) $chatId && (int) $route->bot_id === $botId;

            if (! $owned && ! $force) {
                throw new \RuntimeException("商户号 {$merchantId} 已被其它群绑定，如需改绑请联系管理员");
            }

            $route->fill([
                'bot_id' => $botId,
                'chat_id' => (string) $chatId,
                'third_config_id' => $thirdConfigId ?: $route->third_config_id,
                'feature_id' => $featureId ?: $route->feature_id,
                'status' => true,
                'updated_at' => time(),
            ])->save();
        } else {
            $route = MerchantRoute::query()->create([
                'merchant_id' => $merchantId,
                'bot_id' => $botId,
                'chat_id' => (string) $chatId,
                'third_config_id' => $thirdConfigId,
                'feature_id' => $featureId,
                'status' => true,
                'created_at' => time(),
                'updated_at' => time(),
                'deleted_at' => 0,
            ]);
        }

        $this->forget($merchantId);

        return $route;
    }

    /**
     * 解绑
     */
    public function unbind(string $merchantId, ?string $chatId = null): bool
    {
        $query = MerchantRoute::query()->where('merchant_id', trim($merchantId));

        if ($chatId !== null) {
            $query->where('chat_id', (string) $chatId);
        }

        $deleted = (bool) $query->delete();

        $this->forget($merchantId);

        return $deleted;
    }

    /**
     * 按商户号定位：机器人 / 群 / 上游
     */
    public function resolve(string $merchantId): ?MerchantRoute
    {
        $merchantId = trim((string) $merchantId);

        if ($merchantId === '') {
            return null;
        }

        $key = self::CACHE_PREFIX . $merchantId;

        $cached = Cache::get($key);

        if (is_array($cached) && $cached !== []) {
            // 缓存里只放定位用字段，取出来重建模型（避免把整个模型序列化进缓存）
            return $this->hydrate($cached);
        }

        $route = MerchantRoute::query()
            ->where('merchant_id', $merchantId)
            ->where('status', true)
            ->first();

        if (! $route) {
            return null;
        }

        Cache::put($key, [
            'id' => $route->id,
            'merchant_id' => $route->merchant_id,
            'bot_id' => (int) $route->bot_id,
            'chat_id' => (string) $route->chat_id,
            'third_config_id' => $route->third_config_id ? (int) $route->third_config_id : null,
            'feature_id' => $route->feature_id ? (int) $route->feature_id : null,
        ], self::CACHE_TTL);

        return $route;
    }

    /**
     * 某群绑了哪些商户
     */
    public function forChat(string $chatId): array
    {
        return MerchantRoute::query()
            ->where('chat_id', (string) $chatId)
            ->where('status', true)
            ->pluck('merchant_id')
            ->toArray();
    }

    private function hydrate(array $row): MerchantRoute
    {
        $route = new MerchantRoute();

        $route->forceFill($row);
        $route->exists = true;

        return $route;
    }

    private function forget(string $merchantId): void
    {
        Cache::forget(self::CACHE_PREFIX . trim($merchantId));
    }
}
