<?php

declare(strict_types=1);

namespace Modules\Telegram\Console\Commands;

use Catch\CatchAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * 模块迁移（覆盖 CatchAdmin 自带的 catch:migrate）
 *
 * 原版有两个问题，导致线上排障极其痛苦：
 *   1. 用 Artisan::call('migrate') 执行，子命令输出被缓冲吞掉——
 *      成功时看不到执行了哪些迁移，失败时也看不到报错；
 *   2. initialize() 校验模块失败时直接 exit，且异常不输出，
 *      命令跑完「一点输出都没有」，根本不知道发生了什么。
 *
 * 现在改为：
 *   - 迁移前先算出「待执行 / 已执行」清单，明确展示；
 *   - 逐个执行并打印 ✔ 成功 / ✘ 失败 / ✘ 异常，失败时附上报错详情；
 *   - 任一迁移失败即中止并以非 0 退出码结束，方便脚本判断；
 *   - 有异常时 report() 落到日志，同时把要点打印到控制台。
 */
class MigrateRun extends Command
{
    protected $signature = 'catch:migrate {module} {--force}';

    protected $description = '执行指定模块的迁移（显示逐条结果与错误详情）';

    public function handle(): int
    {
        $module = (string) $this->argument('module');

        $dir = CatchAdmin::getModuleMigrationPath($module);

        if (! File::isDirectory($dir)) {
            $this->error("模块 [$module] 的迁移目录不存在");
            $this->line("  期望路径：{$dir}");
            $this->line('  提示：模块名区分大小写，可执行 php artisan catch:module 查看已启用模块');

            return self::FAILURE;
        }

        // File::files() 返回的是数组，不是集合
        $files = File::files($dir);

        if (count($files) === 0) {
            $this->warn("模块 [$module] 下没有迁移文件");

            return self::SUCCESS;
        }

        $base = Str::of(CatchAdmin::getModuleRelativePath($dir))->remove('.');
        $repository = app('migrator')->getRepository();

        // 迁移前先算清单：哪些要跑、哪些已跑过
        // 注意：迁移表都还没建时（repositoryExists=false）不能调getRan()
        $ran = $repository->repositoryExists()
            ? $repository->getRan()
            : [];

        $pending = [];
        $done = 0;

        foreach ($files as $file) {
            $name = $file->getFilenameWithoutExtension();

            if (! in_array($name, $ran, true)) {
                $pending[] = $file;
            } else {
                $done++;
            }
        }

        $this->info(sprintf(
            '模块 [%s]：迁移文件 %d 个 ｜ 待执行 %d ｜ 已执行 %d',
            $module,
            count($files),
            count($pending),
            $done
        ));

        if (! $pending) {
            $this->info('没有待执行的迁移，无需操作。');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<info>待执行迁移：</info>');

        $failed = [];

        foreach ($pending as $file) {
            $name = $file->getFilenameWithoutExtension();
            $path = $base . $file->getFilename();

            $this->line("  → {$name}");

            try {
                $exitCode = Artisan::call('migrate', [
                    '--path' => $path,
                    '--force' => true,
                ]);

                $output = trim(Artisan::output());

                if ($exitCode === 0) {
                    $this->line('    <info>✔ 成功</info>');
                } else {
                    $failed[] = $name;
                    $this->line("    <error>✘ 失败（退出码 {$exitCode}）</error>");

                    if ($output !== '') {
                        $this->emitDetail($output);
                    }
                }
            } catch (\Throwable $e) {
                $failed[] = $name;
                $this->line('    <error>✘ 异常：' . $e->getMessage() . '</error>');

                // 打印最关键的调用位置，省去翻长栈的麻烦
                $this->emitDetail($this->summarizeTrace($e));

                report($e);
            }
        }

        $this->newLine();

        if ($failed) {
            $this->error(sprintf(
                '模块 [%s] 迁移失败 %d 个：%s',
                $module,
                count($failed),
                implode(', ', $failed)
            ));
            $this->error('已中止后续迁移，请修复上述问题后重新执行本命令。');

            return self::FAILURE;
        }

        $this->info(sprintf('模块 [%s] 迁移完成，成功执行 %d 个。', $module, count($pending)));
        $this->line('提示：新加了表结构变更后，建议再执行 php artisan config:clear / route:clear');

        return self::SUCCESS;
    }

    /**
     * 缩进输出多行错误详情
     *
     * Laravel 12 的 Command 没有 block() 方法，这里自行实现缩进与截断，
     * 避免几十行日志把控制台刷爆。
     */
    protected function emitDetail(string $text): void
    {
        $lines = preg_split('/\R/', trim($text)) ?: [];
        $max = 12;

        foreach (array_slice($lines, 0, $max) as $line) {
            $this->line('      <error>' . $line . '</error>');
        }

        if (count($lines) > $max) {
            $this->line(sprintf('      <comment>… 其余 %d 行已省略</comment>', count($lines) - $max));
        }
    }

    /**
     * 从异常栈里挑出最可能相关的几行，避免打印几十行无用信息
     */
    protected function summarizeTrace(\Throwable $e): string
    {
        if ($e instanceof \Illuminate\Database\QueryException) {
            $database = '-';

            // QueryException 没有 getDatabase()，从 connection 实例上取
            if (method_exists($e, 'getConnection')) {
                $connection = $e->getConnection();

                if (is_object($connection) && method_exists($connection, 'getDatabaseName')) {
                    $database = (string) $connection->getDatabaseName();
                }
            }

            return sprintf(
                "SQLSTATE[%s]\n%s\n连接: %s\n库: %s\nSQL: %s",
                (string) $e->getCode(),
                $e->getMessage(),
                (method_exists($e, 'getConnectionName') ? (string) $e->getConnectionName() : '-'),
                $database,
                (method_exists($e, 'getSql') ? (string) $e->getSql() : '-')
            );
        }

        $lines = [];

        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? '';

            // 只保留项目内与 migrations 相关的调用位置
            if ($file && (str_contains($file, '/modules/') || str_contains($file, '/database/migrations/'))) {
                $lines[] = sprintf('%s:%d', str_replace(base_path() . '/', '', $file), $frame['line'] ?? 0);
            }

            if (count($lines) >= 5) {
                break;
            }
        }

        return $lines ? implode("\n", $lines) : $e->getFile() . ':' . $e->getLine();
    }
}