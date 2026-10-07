<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Telegram\Http\Controllers\Api\CollectPhoneApiController;
use Modules\Telegram\Http\Controllers\Api\TelethonEventController;
use Modules\Telegram\Http\Controllers\Api\WebHookController;
use Modules\Telegram\Http\Controllers\BotsController;
use Modules\Telegram\Http\Controllers\TelegramApiUserController;

// API 路由组
Route::prefix('api')->group(function () {

    // 采集相关路由 - Collect Routes
    Route::prefix('collect')->middleware(['validate.apikey', 'throttle:60,1'])->group(function () {
        Route::get('startIndex/{scantime}', [CollectPhoneApiController::class, 'startIndex'])
            ->name('api.collect.startIndex');
        Route::post('pushScanLog', [CollectPhoneApiController::class, 'pushScanLog'])
            ->name('api.collect.pushScanLog');
        Route::post('appLogin', [CollectPhoneApiController::class, 'appLogin'])
            ->name('api.collect.appLogin');
        Route::post('completeLogin', [CollectPhoneApiController::class, 'completeLogin'])
            ->name('api.collect.completeLogin');
        Route::post('updateLoginStatus', [CollectPhoneApiController::class, 'updateLoginStatus'])
            ->name('api.collect.updateLoginStatus');
        Route::get('getTUser/{id}', [CollectPhoneApiController::class, 'getTUser'])
            ->whereNumber('id')
            ->name('api.collect.getTUser');
    });

    // Webhook 路由 - Webhook Routes
    Route::prefix('webhook')->group(function () {
        // Telegram 回调入口：仅用 bot 自己的 url_token（secret_token）鉴权（见 handle()），
        // 绝不能用平台的 Api-Key 校验——Telegram 回调时只带 secret_token，带不了平台密钥，
        // 否则回调会被 validate.apikey 拦截导致 webhook 失效、激活后收不到消息。
        // 显式 withoutMiddleware 确保即便更上层（/api 或框架路由加载）套了 validate.apikey 也被排除。
        Route::post('pull', [WebHookController::class, 'handle'])
            ->middleware(['throttle:100,1'])
            ->withoutMiddleware(['validate.apikey'])
            ->name('api.webhook.pull');

        Route::middleware(['validate.apikey', 'throttle:10,1'])->group(function () {
            Route::put('set/{id}', [WebHookController::class, 'setWebhook'])
                ->whereNumber('id')
                ->name('api.webhook.set');
            Route::put('del/{id}', [WebHookController::class, 'deleteWebhook'])
                ->whereNumber('id')
                ->name('api.webhook.delete');
            Route::get('info/{id}', [WebHookController::class, 'getWebhookInfo'])
                ->whereNumber('id')
                ->name('api.webhook.info');
        });
    });

    // Telegram 用户路由 - Telegram User Routes
    Route::prefix('tg/user')->middleware(['validate.apikey'])->group(function () {
        // 登录相关 - Login Routes
        Route::prefix('login')->group(function () {
            Route::post('/', [TelegramApiUserController::class, 'appLogin'])
                ->middleware(['throttle:10,1'])
                ->name('api.tg.user.login');
            Route::post('complete', [TelegramApiUserController::class, 'completeLogin'])
                ->middleware(['throttle:10,1'])
                ->name('api.tg.user.login.complete');
            Route::post('complete-2fa', [TelegramApiUserController::class, 'complete2faLogin'])
                ->middleware(['throttle:10,1'])
                ->name('api.tg.user.login.complete2fa');
            Route::post('check-status', [TelegramApiUserController::class, 'checkLoginStatus'])
                ->middleware(['throttle:60,1'])
                ->name('api.tg.user.login.checkStatus');
        });

        // 其他用户操作
        Route::post('logout', [TelegramApiUserController::class, 'logout'])
            ->middleware(['throttle:10,1'])
            ->name('api.tg.user.logout');

        Route::post('operate/{type}/feature', [TelegramApiUserController::class, 'operateFeature'])
            ->middleware(['throttle:10,1'])
            ->whereIn('type', ['send', 'delete', 'edit'])
            ->name('api.tg.user.operateFeature');

        Route::post('sync/groups', [TelegramApiUserController::class, 'syncGroups'])
            ->middleware(['throttle:10,1'])
            ->name('api.tg.user.syncGroups');
    });

    // Bot 路由 - Bot Routes
    Route::prefix('bot')->middleware(['validate.apikey', 'throttle:20,1'])->group(function () {
        Route::post('send/group', [BotsController::class, 'sendToGroup'])
            ->name('api.bot.sendToGroup');
    });

    // Telethon（Python）事件回调 - 由 telegram-py 服务调用，无需 apikey（控制器内用 X-Token 鉴权）
    Route::prefix('telegram/events')->group(function () {
        Route::post('emoji', [TelethonEventController::class, 'emoji'])
            ->middleware(['throttle:120,1'])
            ->name('api.telethon.event.emoji');
        Route::post('member', [TelethonEventController::class, 'member'])
            ->middleware(['throttle:120,1'])
            ->name('api.telethon.event.member');
    });
});
