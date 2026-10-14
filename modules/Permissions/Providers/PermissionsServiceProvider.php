<?php

namespace Modules\Permissions\Providers;

use Catch\CatchAdmin;
use Catch\Providers\CatchModuleServiceProvider;
use Illuminate\Support\Facades\File;
use Modules\Permissions\Middlewares\PermissionGate;

class PermissionsServiceProvider extends CatchModuleServiceProvider
{
    /**
     * middlewares
     *
     * @return string[]
     */
    protected function middlewares(): array
    {
       return [PermissionGate::class];
    }

    /**
     * route path
     *
     * @return string|array
     */
    public function moduleName(): string|array
    {
        // TODO: Implement path() method.
        return 'permissions';
    }

    /**
     * 注册 Console\Commands 下的命令（与 Telegram 模块一致，目录一放即生效）
     */
    protected function registerCommands(): void
    {
        $commands = [];

        foreach (glob(__DIR__ . '/../Console/Commands/*.php') as $file) {
            $class = $this->getClassFromFile($file);

            if ($class && class_exists($class)) {
                $commands[] = $class;
            }
        }

        if (! empty($commands)) {
            $this->commands($commands);
        }
    }

    protected function getClassFromFile($file): ?string
    {
        $content = File::get($file);

        if (preg_match('/namespace\s+([^;]+);/', $content, $ns)
            && preg_match('/class\s+(\w+)/', $content, $cls)) {
            return trim($ns[1]) . '\\' . $cls[1];
        }

        return null;
    }

    public function register(): void
    {
        parent::register();

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
        }
    }
}
