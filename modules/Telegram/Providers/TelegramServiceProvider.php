<?php

namespace Modules\Telegram\Providers;

use Catch\Providers\CatchModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class TelegramServiceProvider extends CatchModuleServiceProvider
{

    public function register(): void
    {
        $this->registerProvider();
        $this->loadModuleConfig();
    }

    /**
     * 合并模块自己的 config/*.php
     *
     * Catch 不会自动加载模块 config 目录（config('wallet.*') / config('hook.*')
     * 一直是 null），导致写在里面的默认值全部失效、env 也读不到。这里显式合并：
     *   modules/Telegram/config/hook.php   → config('hook.*')
     *   modules/Telegram/config/wallet.php → config('wallet.*')
     */
    protected function loadModuleConfig(): void
    {
        foreach (glob(__DIR__ . '/../config/*.php') as $file) {
            $key = basename($file, '.php');

            config([$key => array_merge(
                require $file,
                is_array(config($key, [])) ? config($key, []) : []
            )]);
        }
    }

    /**
     * route path
     *
     * @return string
     */
    public function moduleName(): string
    {
        // TODO: Implement path() method.
        return 'Telegram';
    }

    public function boot()
    {
        $this->loadRoutes();

        // Register events and listeners
        // 注意：Event::listen() 的数组写法 Event::listen([Event=>Listener]) 在本版本无效
        // （Dispatcher::listen 对数组只取 value 当事件名、listener 变 null），必须用两参形式逐条注册。
        Event::listen(\Modules\Telegram\Events\TelegramUpdateReceivedEvent::class, \Modules\Telegram\Listeners\HandleTelegramUpdateListener::class);
        Event::listen(\Modules\Telegram\Events\UserGroupMembershipEvent::class, \Modules\Telegram\Listeners\HandleUserGroupMembershipListener::class);

        // Register console commands
        $this->registerCommands();
    }

    protected function registerCommands()
    {
        $commands = [];

        foreach (glob(__DIR__.'/../Console/Commands/*.php') as $file) {
            $class = $this->getClassFromFile($file);

            if ($class && class_exists($class)) {
                $commands[] = $class;
            }
        }

        if (!empty($commands)) {
            $this->commands($commands);
        }
    }

    protected function getClassFromFile($file)
    {
        $content = file_get_contents($file);

        if (preg_match('/namespace (.*?);/', $content, $namespace) &&
            preg_match('/class (\w+)/', $content, $className)
        ) {
            return $namespace[1] . '\\' . $className[1];
        }

        return null;
    }

    protected function loadRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/channels.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/wallet.php');
    }

    protected function registerProvider(): void
    {
        $path = __DIR__;

        foreach (glob($path . '/*.php') as $file) {
            $class = 'Modules\\Telegram\\Providers\\' . basename($file, '.php');
            if ($class !== self::class && class_exists($class)) {
                $this->app->register($class);
            }
        }
    }
}
