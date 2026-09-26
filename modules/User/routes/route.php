<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\AuthController;
use Modules\User\Http\Controllers\UserController;

// login route
//
// 这几个接口不需要登录态，所以 withoutMiddleware 去掉了 catch.route.middlewares
// （里面含 Auth），但这也把限流一起去掉了，导致密码和 6 位 2FA 验证码可以被无限暴力枚举。
// 这里显式补上 throttle（每分钟 10 次，按 IP + 账号维度）。
Route::post('login', [AuthController::class, 'login'])
    ->withoutMiddleware(config('catch.route.middlewares'))
    ->middleware('throttle:10,1');

Route::post('2fa', [AuthController::class, 'completeTwoFactorSetup'])
    ->withoutMiddleware(config('catch.route.middlewares'))
    ->middleware('throttle:10,1');

Route::post('logout', [AuthController::class, 'logout'])->withoutMiddleware(config('catch.route.middlewares'));

// users route
Route::apiResource('users', UserController::class);
Route::put('users/enable/{id}', [UserController::class, 'enable']);
Route::match(['post', 'get'], 'user/online', [UserController::class, 'online']);
Route::get('user/login/log', [UserController::class, 'loginLog']);
Route::get('user/operate/log', [UserController::class, 'operateLog']);
Route::get('user/export', [UserController::class, 'export']);

// routes/api.php
Route::get('user/two-factor-status', [UserController::class, 'getTwoFactorStatus']);
Route::post('user/{id}/two-factor-status', [UserController::class, 'setTwoFactorStatus']);


