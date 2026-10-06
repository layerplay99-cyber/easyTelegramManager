<?php

namespace Modules\Telegram\Providers;

use Catch\Providers\CatchModuleServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\ThirdApiConfig;

class ConfigCacheServiceProvider extends CatchModuleServiceProvider
{
    public function boot()
    {
        // 全新安装时 provider 会先于迁移执行：此时 third_api_configs /
        // group_configs 表还不存在，直接查库会抛 1146 并中断一切 artisan 命令
        // （migrate 也跑不起来，形成死锁）。
        // 因此建表前先判断表是否存在，不存在就跳过缓存。
        if (! Cache::has('third_api_configs') && $this->tableExists('third_api_config')) {
            $this->cacheThirdApiConfigs();
        }
        if (! Cache::has('group_configs') && $this->tableExists('group_configs')) {
            $this->cacheGroupConfigs();
        }
    }

    /**
     * 判断带项目表前缀的表是否存在
     */
    protected function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            // 连不上库等情况一律按「未就绪」处理，避免中断命令
            return false;
        }
    }

    protected function cacheThirdApiConfigs(): void
    {
        $configs = ThirdApiConfig::all();
        $formatted = $configs->mapWithKeys(function ($item) {
            return [$item->id => $item->toArray()];
        })->toArray();
        Cache::forever('third_api_configs', $formatted);
    }

    protected function cacheGroupConfigs()
    {
        $botGroups = BotGroups::with('groupConfig')->get();
        $formatted = $botGroups->mapWithKeys(function ($item) {
            return [$item->chat_id => $item->groupConfig ? $item->groupConfig->toArray():[]];
        })->toArray();
        Cache::forever('group_configs', $formatted);
    }

    protected function moduleName(): string|array
    {
        return 'Telegram';
    }
}
