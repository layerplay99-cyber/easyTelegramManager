<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Telegram\Http\Controllers\Api\CollectPhoneApiController;
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
        Route::post('pull', [WebHookController::class, 'handle'])
            ->middleware(['throttle:100,1'])
            ->name('api.webhook.pull');

        Route::middleware(['validate.apikey', 'throttle:10,1'])->group(function () {
            Route::put('set/{id}', [WebHookController::class, 'setWebhook'])
                ->whereNumber('id')
                ->name('api.webhook.set');
            Route::put('del/{id}', [WebHookController::class, 'deleteWebhook'])
                ->whereNumber('id')
                ->name('api.webhook.delete');
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
});
