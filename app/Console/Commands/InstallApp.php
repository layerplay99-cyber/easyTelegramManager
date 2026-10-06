<?php

namespace App\Console\Commands;

use Catch\Commands\InstallCommand;

/**
 * 容器内 / 生产环境可用的 CatchAdmin 安装命令。
 *
 * 背景（见 vendor/catchadmin/core/src/Commands/InstallCommand.php::publishConfig()）：
 *   catch:install 内部用 Illuminate\Support\Facades\Process 以「子进程」方式执行
 *   `artisan migrate` 等命令：
 *       Process::run(Application::formatCommandString('migrate'))->throw();
 *   子进程没有 TTY，而 Laravel 在 APP_ENV=production 下执行 migrate 前会要求人工确认；
 *   拿不到输入就会直接取消并以退出码 1 结束：
 *       APPLICATION IN PRODUCTION. WARN  Command cancelled.
 *   所以即便给父进程加 -it 也没用 —— 卡住的是那个没有 TTY 的子进程。
 *
 * 解决：本命令在执行原逻辑前，让子进程继承一个「非 production」的 APP_ENV，
 * 从而跳过该确认。其余流程完全复用 CatchAdmin 原实现，不改动 vendor。
 *
 * 用法（容器内）：
 *   php artisan app:install
 */
class InstallApp extends InstallCommand
{
    /** 子进程用来跳过 migrate 确认的环境名 */
    private const BYPASS_ENV = 'local';

    /** @var string */
    protected $signature = 'app:install {--reinstall}';

    /** @var string */
    protected $description = '安装 CatchAdmin（容器内 / production 可用，自动跳过 migrate 的交互确认）';

    public function handle(): void
    {
        // 只影响子进程（artisan migrate / catch:migrate / catch:db:seed）读到的环境，
        // 不改变当前进程已加载的配置。
        if ($this->laravel->environment() === 'production') {
            putenv('APP_ENV=' . self::BYPASS_ENV);
            $_ENV['APP_ENV'] = self::BYPASS_ENV;
            $_SERVER['APP_ENV'] = self::BYPASS_ENV;
        }

        parent::handle();
    }
}
