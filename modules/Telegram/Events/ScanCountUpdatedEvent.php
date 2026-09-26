<?php

namespace Modules\Telegram\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Telegram\Models\TelegramApiUsers;

class ScanCountUpdatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $tUsers;

    /**
     * Create a new event instance.
     */
    public function __construct(TelegramApiUsers $tUsers)
    {
        $this->tUsers = $tUsers;
    }

    /**
     * 事件广播的频道
     */
    public function broadcastOn()
    {
        return new Channel('phone-scan');
    }

    /**
     * 事件广播的数据
     */
    public function broadcastWith()
    {
        return [
            'id' => $this->tUsers->id,
            'scan_count' => $this->tUsers->scan_count,
        ];
    }
}
