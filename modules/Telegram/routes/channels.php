<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// 客服账号登录状态推送（telegramApiUser 页订阅），与 TelegramUserLoginStatusEvent 对齐
Broadcast::channel('telegramUser-status', function ($user) {
    return !is_null($user);
});
