<?php

declare(strict_types=1);

namespace Modules\Telegram\Console\Commands;

use Illuminate\Console\Command;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Models\FeaturesBinds;
use Modules\Telegram\Services\Feature\FeatureDataStore;
use Illuminate\Support\Facades\Cache;

/**
 * 功能相关缓存预热
 *
 * 执行 php artisan optimize 后缓存会被清空，本命令把功能数据与功能绑定
 * 重新从数据库读出写入缓存，避免第一条消息触发回源、也便于确认缓存是否正常。
 *
 * 注意：即便不执行本命令，缓存未命中时也会自动回源重建（Cache-Aside），
 * 本命令只是提前预热 + 提供可视化检查。
 */
class WarmFeatureCache extends Command
{
    protected $signature = 'telegram:warm-feature-cache {--flush : 先清空功能缓存再预热}';

    protected $description = '预热功能数据与功能绑定缓存（optimize 清缓存后建议执行）';

    public function handle(FeatureDataStore $store): int
    {
        if ($this->option('flush')) {
            $store->flushAll();
            $this->line('已清空功能数据缓存');
        }

        // 1) 预热功能数据（按功能整体缓存）
        $featureIds = Features::query()->pluck('id');
        $dataRows = 0;

        foreach ($featureIds as $featureId) {
            $data = $store->reload((int) $featureId);
            $dataRows += count($data);
        }

        $this->info(sprintf(
            '功能数据缓存：%d 个功能，共 %d 条作用域数据',
            $featureIds->count(),
            $dataRows
        ));

        // 2) 预热功能绑定（bot+chat 粒度）
        $bindRows = 0;
        $cacheKeys = 0;

        $binds = FeaturesBinds::with('features')
            ->where('enabled', true)
            ->get();

        foreach ($binds as $bind) {
            if ($bind->bot_id === null || $bind->chat_id === null) {
                continue;
            }

            Cache::put(
                "feature:binds:{$bind->bot_id}:{$bind->chat_id}",
                [$bind->toArray()],
                3600
            );

            $cacheKeys++;
        }

        $bindRows = $binds->count();

        $this->info(sprintf(
            '功能绑定缓存：%d 条绑定 → %d 个缓存 key',
            $bindRows,
            $cacheKeys
        ));

        $this->newLine();
        $this->info('预热完成。可执行 php artisan cache:clear 后再跑一次，观察是否自动回源。');

        return self::SUCCESS;
    }
}