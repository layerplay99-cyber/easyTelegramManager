<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

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
    protected $signature = 'app:module:install {module}';

    /** @var string */
    protected $description = '安装 CatchAdmin 模块（容器内 / production 可用，自动跳过 migrate 的交互确认）';

    public function handle(): int
    {
        // 只影响子进程 / 被调用的 Artisan 命令读到的环境，不改变当前进程已加载的配置
        if ($this->laravel->environment() === 'production') {
            putenv('APP_ENV=' . self::BYPASS_ENV);
            $_ENV['APP_ENV'] = self::BYPASS_ENV;
            $_SERVER['APP_ENV'] = self::BYPASS_ENV;
        }

        return Artisan::call('catch:module:install', [
            'module' => $this->argument('module'),
        ]);
    }
}
