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

        $registered = $this->isInstalled($module);

        if ($registered) {
            /*
             * 关键：模块已注册时，绝不能再调 `catch:module:install`（即便带 --f）。
             * vendor 的 ModuleInstallCommand::initialize() 会在 install 之外判断，
             * 带 --f 只表示「跳过检查」，随后 Installer::create() 仍会撞上
             * 已存在的模块而抛 "Module [x] has been created" —— 于是首次失败后
             * 永远无法重装，形成死局。
             *
             * 正确做法：跳过 create，只补跑「迁移 + seed」。
             * 这两步都是幂等的（已执行的迁移会跳过），可以安全地反复执行。
             */
            $this->info("模块 [{$module}] 已注册，跳过 create，仅补跑迁移与 seed。");

            try {
                $exitCode = $this->call('catch:migrate', ['module' => $module, '--force' => true]);
                if ($exitCode !== 0) {
                    $this->error("模块 [{$module}] 迁移失败（退出码 {$exitCode}），请查看上方输出。");
                    return $exitCode;
                }
                $exitCode = $this->call('catch:db:seed', ['module' => $module]);
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("模块 [{$module}] 安装抛出异常：");
                $this->line('  ' . $e->getMessage());
                if ($this->output->isVerbose()) {
                    $this->line($e->getTraceAsString());
                }
                return self::FAILURE;
            }

            if ($exitCode !== 0) {
                $this->error("模块 [{$module}] seed 失败（退出码 {$exitCode}），请查看上方输出。");
                return $exitCode;
            }

            $this->info("模块 [{$module}] 补装成功 ✅");
            return self::SUCCESS;
        }

        $this->info("开始安装模块 [{$module}]（已跳过 migrate 的交互确认）");

        try {

            $exitCode = $this->call('catch:module:install', ['module' => $module]);
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
