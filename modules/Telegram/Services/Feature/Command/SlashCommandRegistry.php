<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Command;

use Illuminate\Support\Facades\File;
use Modules\Telegram\Contracts\SlashCommand;
use Modules\Telegram\Services\LogMessageService;

/**
 * 斜杠命令注册表
 *
 * 负责：命令类的注册、自动发现、按名字查找、向后台暴露命令定义。
 * 实例一律通过容器解析（app()->make），这样命令类构造函数里的依赖注入才生效。
 */
class SlashCommandRegistry
{
    /**
     * 命令名 => 类名
     */
    protected array $commands = [];

    protected bool $discovered = false;

    public function __construct(
        protected readonly LogMessageService $logMessageService
    ) {}

    /**
     * 手动注册一个命令
     */
    public function register(string $commandClass): void
    {
        if (! class_exists($commandClass)) {
            return;
        }

        $reflection = new \ReflectionClass($commandClass);

        if (! $reflection->implementsInterface(SlashCommand::class) || $reflection->isAbstract()) {
            return;
        }

        /** @var SlashCommand $instance */
        $instance = app($commandClass);

        $this->commands[strtolower($instance->name())] = $commandClass;
    }

    /**
     * 扫描 Command 目录，自动注册所有实现了 SlashCommand 的类
     */
    public function discover(): void
    {
        if ($this->discovered) {
            return;
        }

        $dir = __DIR__;

        foreach (File::allFiles($dir) as $file) {
            $class = __NAMESPACE__ . '\\' . $file->getBasename('.php');

            if (! class_exists($class)) {
                continue;
            }

            try {
                $this->register($class);
            } catch (\Throwable $e) {
                $this->logMessageService->createLaravelLog(
                    'slash_command',
                    ['class' => $class, 'error' => $e->getMessage()],
                    '注册斜杠命令失败',
                    'warning'
                );
            }
        }

        $this->discovered = true;
    }

    /**
     * 是否注册了某命令
     */
    public function has(string $name): bool
    {
        $this->discover();

        return isset($this->commands[strtolower($name)]);
    }

    /**
     * 取命令实例（经容器解析，构造函数注入可用）
     */
    public function get(string $name): ?SlashCommand
    {
        $this->discover();

        $class = $this->commands[strtolower($name)] ?? null;

        if (! $class) {
            return null;
        }

        try {
            $instance = app($class);

            return $instance instanceof SlashCommand ? $instance : null;
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'slash_command',
                ['command' => $name, 'class' => $class, 'error' => $e->getMessage()],
                '解析斜杠命令实例失败',
                'error'
            );

            return null;
        }
    }

    /**
     * 所有命令类名（命令名 => 类名）
     */
    public function all(): array
    {
        $this->discover();

        return $this->commands;
    }

    /**
     * 所有命令的定义（供后台下拉选择 handler / 展示说明与配置项）
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->all() as $name => $class) {
            $instance = $this->get($name);

            if (! $instance) {
                continue;
            }

            $definitions[] = [
                'name' => $instance->name(),
                'description' => $instance->description(),
                'usage' => $instance->usage(),
                'params' => $instance->params(),
                'config_schema' => $instance->configSchema(),
                'handler' => $class,
            ];
        }

        return $definitions;
    }
}
