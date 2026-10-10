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
use Modules\Telegram\Http\Controllers\ThirdApiEndpointsController;
use Modules\Telegram\Http\Controllers\ThirdConfigEndpointsController;
use Modules\Telegram\Http\Controllers\BotGroupGroupController;
use Modules\Telegram\Http\Controllers\TelegramApiUserController;
use Modules\Telegram\Http\Controllers\MessageTemplateController;

Route::prefix('telegram')->group(function(){

	Route::apiResource('phone', PhoneController::class);
	Route::apiResource('s/can/log', SCanLogController::class);
	Route::apiResource('bots', BotsController::class);
    Route::apiResource('bot/groups', BotGroupsController::class);
    Route::apiResource('bot/group/group', BotGroupGroupController::class);
    Route::apiResource('bot/group/config', GroupConfigController::class);
    Route::apiResource('bot/group/cp', ServicePeopleController::class);

    // 注意：这些 features/xxx 具体路由必须注册在 apiResource('features') 之前。
    // apiResource 会生成 GET features/{feature}，两段路径的 features/drivers、
    // features/custom、features/options 会被 {feature} 抢先匹配成 show('drivers')，
    // 导致返回空数据（后台执行器下拉没内容）。
    Route::get('features/drivers', [FeaturesController::class, 'drivers']);
    Route::get('features/custom', [FeaturesController::class, 'customFeatures']);
    Route::get('features/options', [FeaturesController::class, 'options']);
    // 命令批量查询：必须注册在 features/{id}/commands 之前，
    // 否则 'features/commands' 会被 {id} 抢先匹配成 feature_id='commands'
    Route::get('features/commands', [FeaturesController::class, 'commands']);

    Route::apiResource('features', FeaturesController::class);
    Route::apiResource('feature/bind', FeatureBindsController::class);
    Route::apiResource('third/config', ThridConfigController::class);

    // 三方接口（从上游配置里拆出来的「接口」层，可被多个功能复用）
    Route::apiResource('third/endpoint', ThirdApiEndpointsController::class);

    // 上游 × 接口 的路径映射：同一功能在不同上游可配不同路径
    Route::get('third/config/{thirdConfigId}/endpoints', [ThirdConfigEndpointsController::class, 'index']);
    Route::put('third/config/{thirdConfigId}/endpoints', [ThirdConfigEndpointsController::class, 'save']);
    Route::apiResource('telegram/api/user', TelegramApiUserController::class);
    Route::apiResource('telegram/service/people', ServicePeopleController::class);
    Route::apiResource('emojis', TelegramEmojisController::class);

    // Plug in Specific Routes
    Route::post('feature/bind/super/store', [FeatureBindsController::class, 'superStore']);

    // 功能下的命令管理（{id} 占位，三段路径，不会与上面的具体路由冲突）
    Route::get('features/{id}/commands', [FeaturesController::class, 'commands']);
    Route::post('features/{id}/commands', [FeaturesController::class, 'saveCommands']);

    // 群发进度（send_id 由 POST bots/...sendToGroup 返回）
    Route::get('message/send/{sendId}', [BotsController::class, 'sendStatus']);

    // 消息模板（结构化 blocks）
    Route::apiResource('message/template', MessageTemplateController::class);
    Route::post('message/template/preview', [MessageTemplateController::class, 'preview']);

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
