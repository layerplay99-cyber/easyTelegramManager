<?php

namespace App\Console\Commands;

use Catch\Facade\Module;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * 容器内 / 生产环境可用的 CatchAdmin 模块安装命令。
 *
 * 背景：catch:module:install 会依次执行 Installer::install() 的
 *   moduleRepository->create()  ->  catch:migrate {module}  ->  catch:db:seed {module}
 * 其中 catch:migrate 内部（vendor/catchadmin/core/src/Commands/Migrate/MigrateRun.php）
 * 是这么跑迁移的：
 *       Artisan::call('migrate', ['--path' => $path, '--force' => $this->option('force')]);
 * 而 Installer::migrate() 调用 catch:migrate 时并没有带 --force：
 *       Process::run(Application::formatCommandString('catch:migrate '. $name))->throw();
 * 于是 APP_ENV=production 时 migrate 被安全确认拦截而取消（不建表），
 * 但 MigrateRun 不检查返回码，照样输出 "migrate success"，
 * 紧接着 catch:db:seed 访问表就报 1146 Table ... doesn't exist。
 *
 * 解决：执行前让子进程继承非 production 的 APP_ENV，跳过 migrate 的确认，
 * 其余完全复用 CatchAdmin 原实现（不改动 vendor）。
 *
 * 用法（容器内）：
 *   php artisan app:module:install cms
 *   php artisan app:module:install system
 *   php artisan app:module:install telegram
 */
class ModuleInstall extends Command
{
    /** 子进程用来跳过 migrate 确认的环境名 */
    private const BYPASS_ENV = 'local';

    /** @var string */
    protected $signature = 'app:module:install {module} {--force}';

    /** @var string */
    protected $description = '安装 CatchAdmin 模块（容器内 / production 可用，自动跳过 migrate 的交互确认）';

    public function handle(): int
    {
        $module = (string) $this->argument('module');

        // 只影响子进程 / 被调用的 Artisan 命令读到的环境，不改变当前进程已加载的配置
        if ($this->laravel->environment() === 'production') {
            putenv('APP_ENV=' . self::BYPASS_ENV);
            $_ENV['APP_ENV'] = self::BYPASS_ENV;
            $_SERVER['APP_ENV'] = self::BYPASS_ENV;
        }

        // 先判断是否已安装：catch:module:install 在已安装时会 $this->error(...) 后直接 exit，
        // 那会让本命令后续代码（成功提示）根本执行不到，看起来像静默退出。
        if (! $this->option('force') && $this->isInstalled($module)) {
            $this->warn("模块 [{$module}] 已安装，跳过。如需强制重新安装请加 --force。");
            return self::SUCCESS;
        }

        $this->info("开始安装模块 [{$module}]（已跳过 migrate 的交互确认）");

        $params = ['module' => $module];
        if ($this->option('force')) {
            // 对应 catch:module:install 的 --f：跳过已安装检查，强制重装
            $params['--f'] = true;
        }

        try {
            // 用 $this->call() 而不是 Artisan::call()：
            // Artisan::call() 会把子命令输出全部吞进缓冲区，终端看不到任何内容，
            // 异常也被 Symfony 捕获写进缓冲区，导致成功/失败都是静默的。
            // $this->call() 会把输出透传到当前终端。
            $exitCode = $this->call('catch:module:install', $params);
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error("模块 [{$module}] 安装抛出异常：");
            $this->line('  ' . $e->getMessage());
            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }
            return self::FAILURE;
        }

        $this->newLine();
        if ($exitCode !== 0) {
            $this->error("模块 [{$module}] 安装失败（退出码 {$exitCode}），请查看上方输出定位原因。");
            return $exitCode;
        }

        $this->info("模块 [{$module}] 安装成功 ✅");

        return self::SUCCESS;
    }

    /**
     * 复用 vendor 的判断逻辑：已启用模块 + config('catch.module.default') 视为已安装。
     */
    private function isInstalled(string $module): bool
    {
        return Module::getEnabled()
            ->pluck('name')
            ->merge(Collection::make(config('catch.module.default')))
            ->contains(lcfirst($module));
    }
}
