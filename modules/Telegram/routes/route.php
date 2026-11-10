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
use Modules\Telegram\Http\Controllers\MerchantsController;
use Modules\Telegram\Http\Controllers\PlanOrdersController;
use Modules\Telegram\Http\Controllers\PluginsController;
use Modules\Telegram\Http\Controllers\PlansController;
use Modules\Telegram\Http\Controllers\UpstreamBindingController;

Route::prefix('telegram')->group(function(){

	// 平台商户（租户）：只有平台超管能维护
	Route::apiResource('merchants', MerchantsController::class);

	// 套餐：具体路由必须注册在 apiResource('plan/orders') 之前，
	// 否则 plan/orders/balance、plan/orders/purchase 会被 {plan_order} 抢先匹配成 show()
	Route::get('plan/orders/balance', [PlanOrdersController::class, 'balance']);
	Route::post('plan/orders/purchase', [PlanOrdersController::class, 'purchase']);
	Route::post('plan/orders/adjust-balance', [PlanOrdersController::class, 'adjustBalance']);
	Route::get('plan/orders/accounts', [PlanOrdersController::class, 'accounts']);
	Route::apiResource('plans', PlansController::class);
	Route::apiResource('plan/orders', PlanOrdersController::class);

	// 功能插件：只给平台超管（控制器内校验）。
	// 具体路由写在 {slug} 之前，避免 plugins/settings 被当成 slug。
	Route::get('plugins/settings', [PluginsController::class, 'settings']);
	Route::put('plugins/settings', [PluginsController::class, 'saveSettings']);
	Route::get('plugins', [PluginsController::class, 'index']);
	Route::post('plugins', [PluginsController::class, 'store']);
	Route::get('plugins/{slug}', [PluginsController::class, 'show']);
	Route::put('plugins/{slug}', [PluginsController::class, 'update']);
	Route::delete('plugins/{slug}', [PluginsController::class, 'destroy']);

	// 机器人 ↔ 多上游、上游 ↔ 功能
	Route::get('bots/{id}/upstreams', [UpstreamBindingController::class, 'botUpstreams'])
		->name('bots.upstreams.index');
	Route::put('bots/{id}/upstreams', [UpstreamBindingController::class, 'saveBotUpstreams'])
		->name('bots.upstreams.save');
	Route::get('third/config/{id}/features', [UpstreamBindingController::class, 'configFeatures'])
		->name('third-config.features.index');
	Route::put('third/config/{id}/features', [UpstreamBindingController::class, 'saveConfigFeatures'])
		->name('third-config.features.save');

	Route::apiResource('phone', PhoneController::class);
	Route::apiResource('s/can/log', SCanLogController::class);
	Route::apiResource('bots', BotsController::class);
    // 单独改「三方上游」的专用口子。
    // 前端现在走 apiResource 的 PUT bots/{bot}（BotsRequest 已放开局部更新），
    // 因为新路由在开了 route:cache 的环境必须重新 cache 才生效，
    // 没跑就会「route_not_found_or_register」。这条保留给外部调用。
    Route::put('bots/{id}/third-config', [BotsController::class, 'updateThirdConfig']);
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
    // 功能配置弹框的全量元信息（不分页，必须注册在 features/{feature} 之前）
    Route::get('features/upstream-meta', [UpstreamBindingController::class, 'featureMeta'])
        ->name('features.upstream-meta');

    Route::apiResource('features', FeaturesController::class);
    Route::apiResource('feature/bind', FeatureBindsController::class);

    // 与上面的 bot/group/config 末段同为 config，Laravel 取末段生成路由名，
    // 两者都会想叫 config.index —— 平时不报错，但 route:cache 时会直接失败：
    //   "Unable to prepare route [api/telegram/third/config] for serialization.
    //    Another route has already been assigned name [config.index]"
    // 这里显式改成 third-config.* 避让（config.* 归 bot/group/config 所有）。
    Route::apiResource('third/config', ThridConfigController::class)->names([
        'index'   => 'third-config.index',
        'store'   => 'third-config.store',
        'show'    => 'third-config.show',
        'update'  => 'third-config.update',
        'destroy' => 'third-config.destroy',
    ]);

    // 三方入站密钥对：生成时一次性返回明文，吊销后立即失效。
    // 显式命名避开 route:cache 时的重名（third-config.* 已被 apiResource 占用）。
    Route::post('third/config/{id}/keys', [ThridConfigController::class, 'generateKeys'])
        ->name('third-config.keys.generate');
    Route::delete('third/config/{id}/keys', [ThridConfigController::class, 'revokeKey'])
        ->name('third-config.keys.revoke');

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

    // 回调消息转换器（报文 → 群里的文本 + 按钮），按归属链控制谁能改
    Route::get('features/{id}/transformer', [FeaturesController::class, 'transformer']);
    Route::put('features/{id}/transformer', [FeaturesController::class, 'saveTransformer']);

    // 三方推送回调入口 + 路由表（商户ID → 机器人 + 群）
    Route::get('features/{id}/hook', [FeaturesController::class, 'hook']);
    Route::put('features/{id}/hook', [FeaturesController::class, 'saveHook']);
    Route::get('features/{id}/hook/routes', [FeaturesController::class, 'hookRoutes']);
    Route::post('features/{id}/hook/routes', [FeaturesController::class, 'saveHookRoutes']);

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
