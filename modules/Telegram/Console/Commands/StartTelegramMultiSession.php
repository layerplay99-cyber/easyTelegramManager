<?php

namespace Modules\Telegram\Console\Commands;

use Illuminate\Console\Command;
use Modules\Telegram\Services\Madeline\MultiSessionListener;

class StartTelegramMultiSession extends Command
{
    protected $signature = 'telegram:multi-listen';
    protected $description = 'Start multiple MadelineProto sessions with hot reload';

    public function handle()
    {
        $service = new MultiSessionListener();
        $service->startAllSessions();
        while (true) {
            $service->hotReload();
            sleep(5);
        }
    }
}
