<?php

namespace Modules\Telegram\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TelegramUserLoginStatusEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $id;
    public int $login_status;
    public bool $require_2fa;
    public string $message;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(int $id, int $login_status, bool $require_2fa = false, string $message = '')
    {
        $this->id = $id;
        $this->login_status = $login_status;
        $this->require_2fa = $require_2fa;
        $this->message = $message;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        // 与前端 telegramApiUser/index.vue 的 echo.private('telegramUser-status') 对齐
        return new PrivateChannel('telegramUser-status');
    }

    public function broadcastWith()
    {
        return [
            'id' => $this->id,
            'login_status' => $this->login_status,
            'require_2fa' => $this->require_2fa,
            'message' => $this->message,
        ];
    }

    public function broadcastAs()
    {
        // 与前端 channel.listen('.TelegramUserLoginStatus') 对齐
        return 'TelegramUserLoginStatus';
    }
}
