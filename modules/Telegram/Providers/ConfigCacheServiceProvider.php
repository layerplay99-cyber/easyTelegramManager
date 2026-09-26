<?php

namespace Modules\Telegram\Providers;

use Catch\Providers\CatchModuleServiceProvider;
use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\ThirdApiConfig;

class ConfigCacheServiceProvider extends CatchModuleServiceProvider
{
    public function boot()
    {
        if(! Cache::has('third_api_configs')) {
            $this->cacheThirdApiConfigs();
        }
        if(! Cache::has('group_configs')) {
            $this->cacheGroupConfigs();
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
