<?php

declare(strict_types=1);

namespace Modules\Telegram\Console\Commands;

use Illuminate\Console\Command;
use Modules\Telegram\Services\Feature\CustomFeatureRegistry;
use Modules\Telegram\Services\Feature\PlatformEndpointRegistry;

/**
 * 注册 / 同步功能与命令
 *
 * 功能只有一个来源：开发者写的功能代码（Drivers/Custom/ 下继承 BaseCustomFeature 的类）。
 * 命令里不内置任何 preset / 内置定义，避免两处定义同一个功能。
 *
 * 幂等：可反复执行，只登记代码里声明的内容，不动后台已改的 enabled 开关与绑定关系。
 */
class SyncFeatures extends Command
{
    protected $signature = 'telegram:sync-features';

    protected $description = '扫描功能代码并同步到功能列表（Drivers/Custom + 平台接口）';

    public function handle(): int
    {
        $count = 0;

        // 1. 平台接口规范（接入标准，由平台统一维护）
        foreach (PlatformEndpointRegistry::sync() as $code) {
            $this->line("  接口 {$code}");
        }

        $this->newLine();

        // 2. 扫描功能代码：Drivers/Custom/ 下每个类 = 一条功能
        foreach (app(CustomFeatureRegistry::class)->sync() as $name) {
            $this->info("已注册功能：{$name}");
            $count++;
        }

        $this->newLine();
        $this->info("完成，共 {$count} 个功能。可在「功能列表」里调整配置与绑定。");

        return self::SUCCESS;
    }
}