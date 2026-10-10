<?php

declare(strict_types=1);

namespace Modules\Telegram\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Services\Feature\DriverRegistry;

/**
 * 清理功能列表里的旧数据
 *
 * 旧数据 = 老 schema 下建的记录：靠 handler 类名执行、config 里是
 * command/paramscount/rule/thridconfig 那套约定，与新的 driver 体系对不上。
 * 判定依据（满足任一即旧数据）：
 *   1) handler 非空 —— 新体系统一走 driver，handler 恒为空；
 *   2) driver 未在 DriverRegistry 注册 —— 新体系只认已注册的驱动。
 *
 * 用法：
 *   php artisan telegram:cleanup-legacy-features            # 只预览，不删除
 *   php artisan telegram:cleanup-legacy-features --force    # 确认后真删
 */
class CleanupLegacyFeatures extends Command
{
    protected $signature = 'telegram:cleanup-legacy-features {--force}';

    protected $description = '清理功能列表中老 schema 遗留的功能记录（默认只预览）';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $all = Features::query()->orderBy('id')->get();
        $drivers = DriverRegistry::all();

        // 新体系统一走 driver：handler 必须为空、driver 必须是已注册的驱动
        $legacy = $all->filter(function ($feature) use ($drivers) {
            if (trim((string) ($feature->handler ?? '')) !== '') {
                return true;
            }

            return ! isset($drivers[(string) $feature->driver]);
        })->values();

        $keep = $all->reject(fn ($f) => $legacy->contains('id', $f->id))->values();

        $this->info("功能总数：{$all->count()}");
        $this->info("将保留（新体系）：{$keep->count()}");
        $this->warn("将清理（旧数据）：{$legacy->count()}");

        if ($keep->count()) {
            $this->newLine();
            $this->info('保留：');
            foreach ($keep as $f) {
                $this->line(sprintf('  #%-4d %-22s driver=%-26s trigger=%s',
                    $f->id, $f->name, $f->driver, $f->trigger));
            }
        }

        if (! $legacy->count()) {
            $this->newLine();
            $this->info('没有需要清理的旧数据。');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('待清理明细：');

        foreach ($legacy as $f) {
            $config = is_array($f->config) ? json_encode($f->config, JSON_UNESCAPED_UNICODE) : (string) $f->config;
            $this->line(sprintf('  #%-4d %-22s handler=%-34s feature=%s',
                $f->id, $f->name, (string) $f->handler, (string) $f->feature));
            $this->line(sprintf('        config=%s', mb_substr($config, 0, 90)));
        }

        if (! $force) {
            $this->newLine();
            $this->warn('这是预览模式，没有做任何删除。');
            $this->warn('确认无误后加 --force 执行删除。');

            return self::SUCCESS;
        }

        // ---- 执行删除 ----
        $ids = $legacy->pluck('id')->all();

        DB::transaction(function () use ($ids) {
            // 绑定关系
            DB::table('features_binds')->whereIn('feature_id', $ids)->delete();

            // 命令声明（新体系由 Definitions 重新同步）
            DB::table('feature_commands')->whereIn('feature_id', $ids)->delete();

            // 旧日志表（该表本身有设计问题：错误唯一键 + 列名不一致）
            DB::table('features_logs')->whereIn('feature_id', $ids)->delete();

            // 物理删除：旧数据已确认无用，不需要留在回收站里占位
            Features::query()->whereIn('id', $ids)->get()->each->forceDelete();
        });

        $this->newLine();
        $this->info("已物理清理 {$legacy->count()} 条旧功能记录（含其绑定、命令与旧日志）。");
        $this->info('接下来执行 php artisan telegram:sync-features 重新注册内置功能。');

        return self::SUCCESS;
    }
}