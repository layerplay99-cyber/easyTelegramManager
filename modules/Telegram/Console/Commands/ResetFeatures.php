<?php

declare(strict_types=1);

namespace Modules\Telegram\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Models\Features;

/**
 * 重置功能列表为标准定义
 *
 * 用于清理旧 schema 残留 / 配置写坏的情况：
 *   - 删除所有功能、命令、绑定、功能数据
 *   - 重新注册内置功能（binding + 平台接口规范 + 自定义功能）
 *   - 配置回到代码里的 defaultConfig，不再是脏数据
 *
 * 注意：会一并清掉 features_binds（功能与群的绑定关系）与 feature_data，
 *  重置后需在后台重新把功能绑定到群。
 *
 * 用法：
 *   php artisan telegram:reset-features            # 只预览
 *   php artisan telegram:reset-features --force    # 确认后重置
 */
class ResetFeatures extends Command
{
    protected $signature = 'telegram:reset-features {--force}';

    protected $description = '重置功能列表为标准定义（清理旧数据/脏配置）';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $features = Features::query()->orderBy('id')->get();

        $this->info('当前功能列表：');
        $this->newLine();

        foreach ($features as $f) {
            $config = is_array($f->config) ? json_encode($f->config, JSON_UNESCAPED_UNICODE) : '-';

            $this->line(sprintf(
                '  #%-3d %-16s driver=%-26s config=%s',
                $f->id,
                mb_substr((string) $f->name, 0, 16),
                (string) $f->driver,
                mb_substr((string) $config, 0, 70)
            ));
        }

        $this->newLine();

        $bindCount   = DB::table('features_binds')->count();
        $commandCount = FeatureCommands::query()->count();
        $dataCount    = DB::table('feature_data')->count();

        $this->warn(sprintf(
            '将清空：功能 %d 条、命令 %d 条、绑定 %d 条、功能数据 %d 条',
            $features->count(),
            $commandCount,
            $bindCount,
            $dataCount
        ));

        if (! $force) {
            $this->newLine();
            $this->warn('这是预览模式，没有做任何删除。确认无误后加 --force 执行。');

            return self::SUCCESS;
        }

        // ---- 清空（物理删除，避免软删除残留干扰）----
        DB::transaction(function () {
            foreach (['feature_data', 'feature_commands', 'features_binds', 'feature_hooks'] as $table) {
                DB::table($table)->delete();
            }

            Features::query()->get()->each->delete();
        });

        $this->info('已清空功能相关数据。');
        $this->newLine();

        // ---- 重新注册（复用 sync-features 的注册逻辑）----
        $this->call('telegram:sync-features');

        $this->newLine();
        $this->info('重置完成。请到后台确认功能列表，并重新把功能绑定到群。');

        return self::SUCCESS;
    }
}