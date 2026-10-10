<?php

declare(strict_types=1);

use Catch\Middleware\AuthMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Telegram\Http\Controllers\Api\CollectPhoneApiController;
use Modules\Telegram\Http\Controllers\Api\HookController;
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
    Route::prefix('tg/user')->group(function () {
        // 登录相关：外部（采集器 App）调用，只用平台 Api-Key + 签名，没有后台登录态
        Route::prefix('login')->middleware(['validate.apikey'])->group(function () {
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

        // 后台操作：控制器里用 getLoginUser() 按 creator_id 做数据范围，
        // 因此除了平台签名，还必须有后台登录态（AuthMiddleware），
        // 否则会直接抛「登录失效」。
        Route::middleware(['validate.apikey', AuthMiddleware::class])->group(function () {
            Route::post('logout', [TelegramApiUserController::class, 'logout'])
                ->middleware(['throttle:10,1'])
                ->name('api.tg.user.logout');

            // {type} 是消息类型：text / media（与前端「消息类型」下拉、Job 的 type 一致）。
            // 曾误写成 send/delete/edit，导致后台点真人功能全部落到 route_not_found_or_register。
            Route::post('operate/{type}/feature', [TelegramApiUserController::class, 'operateFeature'])
                ->middleware(['throttle:10,1'])
                ->whereIn('type', ['text', 'media'])
                ->name('api.tg.user.operateFeature');

            Route::post('sync/groups', [TelegramApiUserController::class, 'syncGroups'])
                ->middleware(['throttle:10,1'])
                ->name('api.tg.user.syncGroups');
        });
    });

    // Bot 路由 - Bot Routes
    Route::prefix('bot')->middleware(['validate.apikey', 'throttle:20,1'])->group(function () {
        Route::post('send/group', [BotsController::class, 'sendToGroup'])
            ->name('api.bot.sendToGroup');
    });

    // 第三方推送回调入口（第三块功能）：上游回调 POST /api/hooks/{token}
    // 不用平台 Api-Key —— 上游只会带自己的 token，由控制器按 token 定位功能。
    Route::post('hooks/{token}', [HookController::class, 'handle'])
        ->middleware(['throttle:120,1'])
        ->name('api.hook.handle');

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
