<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Models\FeatureData;

/**
 * 功能数据存储（缓存优先 + 写时同步）
 *
 * 背景：功能执行是高频路径（每条消息都会读一次已存数据，如已绑定的商户号），
 * 而这些数据几乎不变（只有重新执行绑定命令时才变），每次都查库是浪费。
 *
 * 策略 Cache-Aside + Write-Through：
 *   读：优先读缓存；缓存不存在（如执行 optimize 后被清空）→ 读库并回填缓存
 *   写：先落库，再同步刷新缓存，保证两边一致
 *
 * 缓存粒度：按「功能」整体缓存该功能在所有作用域下的数据
 *   key = feature:data:{feature_id}
 *   这样单次读取只需 1 次缓存访问；写入时整份重建也只需 1 次 DB 查询，
 *   避免了「逐条回源」在列表页造成的缓存穿透。
 *
 * DB 始终是唯一数据源，缓存只是加速，丢了会自动重建。
 */
class FeatureDataStore
{
    /**
     * 缓存 key 前缀
     */
    private const CACHE_PREFIX = 'feature:data:';

    /**
     * 缓存有效期（秒）
     *
     * 不使用 forever：留一个兜底过期，防止写库成功但刷缓存失败时缓存长期不一致。
     * 30 天足够宽松，且正常情况下有写入就会刷新。
     */
    private const CACHE_TTL = 2592000;

    /**
     * 读取某功能在某作用域下的数据（缓存优先）
     *
     * @return array<string, mixed>
     */
    public function get(int $featureId, string $scopeType, int|string $scopeId): array
    {
        $all = $this->all($featureId);

        $data = $all[$scopeType . ':' . (string) $scopeId] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * 读取某功能在所有作用域下的数据（缓存优先，缓存缺失则回源并回填）
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(int $featureId): array
    {
        $key = self::CACHE_PREFIX . $featureId;

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        // 缓存未命中（首次访问或被 optimize 清空）：读库并回填
        return $this->reload($featureId);
    }

    /**
     * 写入（落库 + 同步刷新缓存）
     *
     * @param array<string, mixed> $data
     */
    public function put(int $featureId, string $scopeType, int|string $scopeId, array $data): void
    {
        $scopeId = (string) $scopeId;

        $row = FeatureData::query()
            ->where('feature_id', $featureId)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->first();

        if ($row) {
            // 合并：局部更新不会覆盖掉其它已存的参数
            $row->data = array_merge(is_array($row->data) ? $row->data : [], $data);
            $row->save();
        } else {
            FeatureData::query()->create([
                'feature_id' => $featureId,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'data' => $data,
                'enabled' => true,
            ]);
        }

        // 写时同步：命令执行后立刻刷新缓存，保证下次读取命中最新值
        $this->refresh($featureId);
    }

    /**
     * 清除某作用域数据（落库 + 同步刷新缓存）
     */
    public function forget(int $featureId, string $scopeType, int|string $scopeId): void
    {
        FeatureData::query()
            ->where('feature_id', $featureId)
            ->where('scope_type', $scopeType)
            ->where('scope_id', (string) $scopeId)
            ->delete();

        $this->refresh($featureId);
    }

    /**
     * 从数据库重新加载并回填缓存
     *
     * @return array<string, array<string, mixed>>
     */
    public function reload(int $featureId): array
    {
        $data = [];

        $rows = FeatureData::query()
            ->where('feature_id', $featureId)
            ->where('enabled', true)
            ->get(['scope_type', 'scope_id', 'data']);

        foreach ($rows as $row) {
            $data[$row->scope_type . ':' . $row->scope_id] = is_array($row->data) ? $row->data : [];
        }

        Cache::put(self::CACHE_PREFIX . $featureId, $data, self::CACHE_TTL);

        return $data;
    }

    /**
     * 刷新缓存（内部重新查库重建）
     */
    public function refresh(int $featureId): void
    {
        $this->reload($featureId);
    }

    /**
     * 清掉缓存（下次读取自动回源）
     */
    public function forgetCache(int $featureId): void
    {
        Cache::forget(self::CACHE_PREFIX . $featureId);
    }

    /**
     * 清掉全部功能数据缓存（配合 php artisan optimize 后重建用）
     */
    public function flushAll(): void
    {
        // 按前缀批量清理，避免依赖各驱动的具体 key
        $store = Cache::getStore();

        if (method_exists($store, 'flushPrefix')) {
            $store->flushPrefix(self::CACHE_PREFIX);
        }
    }
}