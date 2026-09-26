<?php

namespace Modules\Telegram\Providers;

use Catch\Providers\CatchModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class TelegramServiceProvider extends CatchModuleServiceProvider
{

    public function register(): void
    {
        $this->registerProvider();
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
        Event::listen([
            \Modules\Telegram\Events\TelegramUpdateReceivedEvent::class => \Modules\Telegram\Listeners\HandleTelegramUpdateListener::class,
            \Modules\Telegram\Events\UserGroupMembershipEvent::class => \Modules\Telegram\Listeners\HandleUserGroupMembershipListener::class,
        ]);

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
