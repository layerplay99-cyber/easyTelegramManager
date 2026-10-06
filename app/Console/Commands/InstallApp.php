<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * 容器内 / 生产环境可用的 CatchAdmin 安装命令（幂等、可重复执行）。
 *
 * 不直接用 catch:install 的原因：
 *   1) 它内部以无 TTY 的子进程跑 `artisan migrate`，APP_ENV=production 时
 *      会被安全确认拦截而静默跳过（MigrateRun 还不检查返回码，打印假成功）。
 *   2) 它的 handle() catch 到任何异常都会 File::delete(.env)，
 *      首次失败就会连配置一起丢掉。
 *   3) 重装时 permissions 已存在会抛 "Module [x] has been created"，
 *      再触发 (2) 删掉 .env，形成死局。
 *
 * 本命令不调用 parent::handle()，自行按幂等方式编排，全程不删除 .env。
 *
 * 用法：php artisan app:install   /   php artisan app:install --no-seed
 */
class InstallApp extends Command
{
    /** 子进程用来跳过 migrate 交互确认的环境名 */
    private const BYPASS_ENV = 'local';

    /** @var string */
    protected $signature = 'app:install {--seed}';

    /** @var string */
    protected $description = '安装 CatchAdmin（容器内 / production 可用，幂等可重复执行）';

    public function handle(): int
    {
        $this->bypassProductionConfirmation();

        $this->info('开始安装 CatchAdmin（已跳过 migrate 的交互确认）');
        $this->newLine();

        try {
            $this->runMigrations();

            // 默认不跑 seed：模块安装流程内部已经跑过，
            // 重复执行会因 vendor 的 require_once 缺陷而失败（见 runSeeds 注释）。
            if ($this->option('seed')) {
                $this->runSeeds();
            }
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('CatchAdmin 安装失败 ❌');
            $this->line('  ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('CatchAdmin 安装完成 ✅（未删除 .env）');

        return self::SUCCESS;
    }

    /**
     * 迁移顺序：permissions 必须最先 —— 其余模块的迁移会查询 permissions 表，
     * 缺表会抛 SQLSTATE[42S02] 1146。
     */
    private function orderedModules(): array
    {
        return ['permissions', 'user', 'develop', 'cms', 'system', 'telegram'];
    }

    /**
     * production 下 migrate / db:seed 会被安全确认拦截（子进程没有 TTY）。
     * 让子进程继承一个非 production 的 APP_ENV 即可跳过该确认；
     * 只影响子进程读到的环境，不改变当前进程已加载的配置。
     */
    private function bypassProductionConfirmation(): void
    {
        if ($this->laravel->environment() !== 'production') {
            return;
        }

        putenv('APP_ENV=' . self::BYPASS_ENV);
        $_ENV['APP_ENV'] = self::BYPASS_ENV;
        $_SERVER['APP_ENV'] = self::BYPASS_ENV;
    }

    /** 核心迁移 + 各模块迁移（均幂等：只执行尚未跑过的迁移） */
    private function runMigrations(): void
    {
        $exitCode = $this->call('migrate', ['--force' => true]);
        if ($exitCode !== 0) {
            throw new \RuntimeException("核心迁移失败（退出码 {$exitCode}）");
        }
        $this->line('  核心迁移完成');

        // 直接调 catch:migrate 会因子模块未注册而报 "Module [x] Not Found"，
        // 所以复用 app:module:install：未注册 -> 注册+迁移+seed；已注册 -> 只补跑迁移+seed。
        foreach ($this->orderedModules() as $module) {
            $exitCode = $this->call('app:module:install', ['module' => $module]);
            if ($exitCode !== 0) {
                throw new \RuntimeException("模块 [{$module}] 安装失败（退出码 {$exitCode}）");
            }
            $this->line("  模块 [{$module}] 安装完成");
        }
    }

    /**
     * 数据填充。
     *
     * ⚠️ 注意 vendor 缺陷（SeedRun.php:71-72）：
     *     $class = require_once $file->getRealPath();
     *     $class = new $class();
     * require_once 只在首次加载时返回类名，之后再调用返回 true，
     * 于是 `new true()` 抛 "Class name must be a valid object or a string"。
     * 即：同一进程内重复 seed 必然失败；跨进程（单独 artisan 调用）则正常。
     * 因此这里对失败只告警、不阻断安装。
     */
    private function runSeeds(): void
    {
        foreach (['user', 'permissions'] as $module) {
            try {
                $exitCode = $this->call('catch:db:seed', ['module' => $module]);
                if ($exitCode !== 0) {
                    $this->warn("模块 [{$module}] seed 未执行（退出码 {$exitCode}），不影响安装结果");
                }
            } catch (\Throwable $e) {
                $this->warn("模块 [{$module}] seed 异常（多为重复执行所致，可忽略）：{$e->getMessage()}");
            }
        }

        $this->line('  数据填充阶段结束');
    }
}
