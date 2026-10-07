<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Modules\Telegram\Models\FeatureData;

/**
 * 功能数据存储
 *
 * 替代原「group_configs 宽表 + Cache::forever('group_configs')」的写法：
 *   - 数据落库，不再拿缓存当存储（缓存只该是加速，不该是唯一来源）
 *   - 按 功能 + 作用域 存 json，功能有几个参数就存几个，不需要加列
 *   - 绑定类功能（绑定商户 / 绑定客服）天然就是「一个功能往一个作用域写数据」
 */
class FeatureDataStore
{
    /**
     * 读取某功能在某作用域下的数据
     *
     * @return array<string, mixed>
     */
    public function get(int $featureId, string $scopeType, int|string $scopeId): array
    {
        $row = FeatureData::query()
            ->where('feature_id', $featureId)
            ->where('scope_type', $scopeType)
            ->where('scope_id', (string) $scopeId)
            ->first();

        if (! $row) {
            return [];
        }

        return is_array($row->data) ? $row->data : [];
    }

    /**
     * 写入（存在则合并，不存在则新建）
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

            return;
        }

        FeatureData::query()->create([
            'feature_id' => $featureId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'data' => $data,
            'enabled' => true,
        ]);
    }

    /**
     * 清除某作用域数据
     */
    public function forget(int $featureId, string $scopeType, int|string $scopeId): void
    {
        FeatureData::query()
            ->where('feature_id', $featureId)
            ->where('scope_type', $scopeType)
            ->where('scope_id', (string) $scopeId)
            ->delete();
    }
}