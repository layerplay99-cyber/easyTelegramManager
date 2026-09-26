<?php

namespace Modules\Telegram\Events;

use Illuminate\Queue\SerializesModels;
use Telegram\Bot\Objects\Update;

class TelegramUpdateReceivedEvent
{
    use SerializesModels;

    public $update;
    public $bot;

    public function __construct($bot, Update $update)
    {
        $this->update = $update;
        $this->bot = $bot;
    }
}
