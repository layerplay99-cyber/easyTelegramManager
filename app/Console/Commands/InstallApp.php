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

    /** 安装过程中是否发生失败（由 publishConfig() 记录，因为父类吞异常） */
    private bool $installFailed = false;

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

        $this->info('开始安装 CatchAdmin（已跳过 migrate 的交互确认）');

        // 注意：parent::handle() 内部就 catch 了异常（还会 File::delete 掉 .env），
        // 不会向外抛，所以这里拿不到异常，只能靠下面两个信号判断是否失败。
        $envPath = app()->environmentFilePath();
        $envExisted = file_exists($envPath);

        parent::handle();

        $envDeleted = $envExisted && ! file_exists($envPath);
        $failed = $this->installFailed || $envDeleted;

        $this->newLine();
        if ($failed) {
            $this->error('CatchAdmin 安装失败 ❌ —— 请看上方错误输出。');
            if ($envDeleted) {
                $this->error("注意：安装失败时原命令会删除 {$envPath}，请从备份恢复后重试。");
            }
            return;
        }

        $this->info('CatchAdmin 安装完成 ✅');
    }

    /**
     * 父类把异常包成 FailedException 抛给 handle() 的 catch（该 catch 会删 .env 并打印错误）。
     * 这里插一脚记录失败状态，好让 handle() 能给出明确结论。
     */
    protected function publishConfig(): void
    {
        try {
            parent::publishConfig();
        } catch (\Throwable $e) {
            $this->installFailed = true;
            throw $e;
        }
    }
}
