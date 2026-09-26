<?php

use Illuminate\Support\Facades\Route;
use Modules\Telegram\Http\Controllers\FeatureBindsController;
use Modules\Telegram\Http\Controllers\FeaturesController;
use Modules\Telegram\Http\Controllers\BotGroupsController;
use Modules\Telegram\Http\Controllers\BotsController;
use Modules\Telegram\Http\Controllers\GroupConfigController;
use Modules\Telegram\Http\Controllers\PhoneController;
use Modules\Telegram\Http\Controllers\SCanLogController;
use Modules\Telegram\Http\Controllers\ServicePeopleController;
use Modules\Telegram\Http\Controllers\TelegramEmojisController;
use Modules\Telegram\Http\Controllers\ThridConfigController;
use Modules\Telegram\Http\Controllers\BotGroupGroupController;
use Modules\Telegram\Http\Controllers\TelegramApiUserController;

Route::prefix('telegram')->group(function(){

	Route::apiResource('phone', PhoneController::class);
	Route::apiResource('s/can/log', SCanLogController::class);
	Route::apiResource('bots', BotsController::class);
    Route::apiResource('bot/groups', BotGroupsController::class);
    Route::apiResource('bot/group/group', BotGroupGroupController::class);
    Route::apiResource('bot/group/config', GroupConfigController::class);
    Route::apiResource('bot/group/cp', ServicePeopleController::class);
    Route::apiResource('features', FeaturesController::class);
    Route::apiResource('feature/bind', FeatureBindsController::class);
    Route::apiResource('third/config', ThridConfigController::class);
    Route::apiResource('telegram/api/user', TelegramApiUserController::class);
    Route::apiResource('telegram/service/people', ServicePeopleController::class);
    Route::apiResource('emojis', TelegramEmojisController::class);

    // Plug in Specific Routes
    Route::post('feature/bind/super/store', [FeatureBindsController::class, 'superStore']);

    // 代码里已实现的斜杠命令清单（后台新增命令时选，避免手敲 handler）
    Route::get('features/slash/commands', [FeaturesController::class, 'slashCommands']);

    // 同步机器人所在群（Bot API 无法枚举群，只能基于已知 chat_id 校验归属）
    Route::post('bots/{id}/sync/groups', [BotsController::class, 'syncGroups']);

    // Excel Phone Import & Export
    Route::post('phone/import', [PhoneController::class, 'import']);
    Route::get('phone/import/progress', [PhoneController::class, 'importProgress'])->withoutMiddleware(config('catch.route.middlewares'));
    Route::get('phone/sheet/export', [PhoneController::class, 'export']);
    Route::get('phone/template/export', [PhoneController::class, 'downloadPhoneTemplate']);

     // Excel TelegramApiUser Import & Export（原 tusers 表已并入 telegram_api_users）
    Route::post('telegram/api/user/import', [TelegramApiUserController::class, 'import']);
    Route::get('telegram/api/user/export', [TelegramApiUserController::class, 'export']);
    Route::get('telegram/api/user/template', [TelegramApiUserController::class, 'downloadTemplate']);
});
