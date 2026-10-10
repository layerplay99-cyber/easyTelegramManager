<?php

use Catch\Middleware\AuthMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Telegram\Http\Controllers\WalletCallbackController;
use Modules\Telegram\Http\Controllers\WalletController;
use Modules\Telegram\Http\Controllers\WalletMiniAppController;
use Modules\Telegram\Http\Controllers\ExchangeRateController;
use Modules\Telegram\Http\Controllers\LedgerController;
use Modules\Telegram\Http\Controllers\MemberController;
use Modules\Telegram\Http\Controllers\PaymentChannelController;
use Modules\Telegram\Http\Controllers\RechargeOrderController;
use Modules\Telegram\Http\Controllers\WithdrawOrderController;
use Modules\Telegram\Http\Controllers\RiskControlController;
use Modules\Telegram\Http\Controllers\TransactionLimitController;

Route::prefix('api/telegram')->group(function () {

    // 后台管理类接口：必须后台登录态 + 限流
    // （这一组原本一个中间件都没有，任何人都能调「调整余额」改钱）
    Route::middleware([AuthMiddleware::class, 'throttle:120,1'])->group(function () {

    // 钱包管理路由
    Route::prefix('wallet')->group(function () {

        // 钱包列表
        Route::get('/', [WalletController::class, 'index']);

        // 创建钱包
        Route::post('/', [WalletController::class, 'store']);

        // 手动调整余额（奖励/扣款）
        Route::post('{id}/adjust-balance', [WalletController::class, 'adjustBalance']);

        // 冻结/解冻钱包
        Route::post('{id}/toggle-status', [WalletController::class, 'toggleStatus']);

        // 钱包统计
        Route::get('statistics', [WalletController::class, 'statistics']);
    });

    // 说明：机器人钱包菜单原来注册了两条 HTTP 路由（wallet/menu/main、wallet/menu/callback），
    // 但控制器方法签名收的是 Telegram Update 对象，Laravel 根本没法注入，这两条路由从来不可用。
    // 机器人侧的钱包交互改为走功能体系（Drivers/Custom/Wallet/*）+ callback 驱动。

    // 账本流水（只读）
    Route::prefix('ledger')->group(function () {
        Route::get('/', [LedgerController::class, 'index']);
        Route::get('types', [LedgerController::class, 'types']);
    });

    // 会员管理路由
    Route::prefix('member')->group(function () {

        // 会员列表
        Route::get('/', [MemberController::class, 'index']);

        // 会员详情
        Route::get('{id}', [MemberController::class, 'show']);

        // 更新会员状态
        Route::post('{id}/status', [MemberController::class, 'updateStatus']);

        // 重置支付密码
        Route::post('{id}/reset-payment-password', [MemberController::class, 'resetPaymentPassword']);

        // 会员统计
        Route::get('{id}/statistics', [MemberController::class, 'statistics']);

        // 会员账本记录
        Route::get('{id}/ledgers', [MemberController::class, 'ledgers']);
    });

    // 充值订单路由
    Route::prefix('recharge-order')->group(function () {

        // 充值订单列表
        Route::get('/', [RechargeOrderController::class, 'index']);

        // 充值订单详情
        Route::get('{id}', [RechargeOrderController::class, 'show']);

        // 完成充值订单
        Route::post('{id}/complete', [RechargeOrderController::class, 'complete']);

        // 取消充值订单
        Route::post('{id}/cancel', [RechargeOrderController::class, 'cancel']);

        // 充值统计
        Route::get('statistics/data', [RechargeOrderController::class, 'statistics']);

        // 导出充值订单
        Route::get('export', [RechargeOrderController::class, 'export']);
    });

    // 提现订单路由
    Route::prefix('withdraw-order')->group(function () {

        // 提现订单列表
        Route::get('/', [WithdrawOrderController::class, 'index']);

        // 提现订单详情
        Route::get('{id}', [WithdrawOrderController::class, 'show']);

        // 完成提现订单
        Route::post('{id}/complete', [WithdrawOrderController::class, 'complete']);

        // 取消提现订单
        Route::post('{id}/cancel', [WithdrawOrderController::class, 'cancel']);

        // 处理中状态
        Route::post('{id}/processing', [WithdrawOrderController::class, 'processing']);

        // 批量处理
        Route::post('batch-process', [WithdrawOrderController::class, 'batchProcess']);

        // 提现统计
        Route::get('statistics/data', [WithdrawOrderController::class, 'statistics']);
    });

    // 汇率管理路由
    Route::prefix('exchange-rate')->group(function () {

        // 汇率列表
        Route::get('/', [ExchangeRateController::class, 'index']);

        // 创建汇率
        Route::post('/', [ExchangeRateController::class, 'store']);

        // 更新汇率
        Route::put('{id}', [ExchangeRateController::class, 'update']);

        // 删除汇率
        Route::delete('{id}', [ExchangeRateController::class, 'destroy']);

        // 获取汇率
        Route::get('rate', [ExchangeRateController::class, 'getRate']);

        // 货币转换
        Route::post('convert', [ExchangeRateController::class, 'convert']);
    });

    // 支付通道管理路由
    Route::prefix('payment-channel')->group(function () {

        // 支付通道列表
        Route::get('/', [PaymentChannelController::class, 'index']);

        // 创建支付通道
        Route::post('/', [PaymentChannelController::class, 'store']);

        // 更新支付通道
        Route::put('{id}', [PaymentChannelController::class, 'update']);

        // 删除支付通道
        Route::delete('{id}', [PaymentChannelController::class, 'destroy']);

        // 切换状态
        Route::post('{id}/toggle-status', [PaymentChannelController::class, 'toggleStatus']);

        // 获取可用通道
        Route::get('available', [PaymentChannelController::class, 'available']);
    });

    // 风控管理路由
    Route::prefix('risk-control')->group(function () {

        // 风控规则列表
        Route::get('rules', [RiskControlController::class, 'rules']);

        // 创建风控规则
        Route::post('rules', [RiskControlController::class, 'createRule']);

        // 更新风控规则
        Route::put('rules/{id}', [RiskControlController::class, 'updateRule']);

        // 删除风控规则
        Route::delete('rules/{id}', [RiskControlController::class, 'deleteRule']);

        // 切换规则状态
        Route::post('rules/{id}/toggle-status', [RiskControlController::class, 'toggleRuleStatus']);

        // 风控日志列表
        Route::get('logs', [RiskControlController::class, 'logs']);

        // 处理风控日志
        Route::post('logs/{id}/handle', [RiskControlController::class, 'handleLog']);

        // 风控统计
        Route::get('statistics', [RiskControlController::class, 'statistics']);
    });

    // 交易限制路由
    Route::prefix('transaction-limit')->group(function () {

        // 交易限制列表
        Route::get('/', [TransactionLimitController::class, 'index']);

        // 创建交易限制
        Route::post('/', [TransactionLimitController::class, 'store']);

        // 更新交易限制
        Route::put('{id}', [TransactionLimitController::class, 'update']);

        // 删除交易限制
        Route::delete('{id}', [TransactionLimitController::class, 'destroy']);

        // 切换状态
        Route::post('{id}/toggle-status', [TransactionLimitController::class, 'toggleStatus']);

        // 获取限制信息
        Route::get('limit', [TransactionLimitController::class, 'getLimit']);
    });

    }); // 后台管理组（需登录 + 限流）

    // 上游回调：第三方支付/代付通知到账单状态。
    // 不能挂后台登录态（上游没有我们的账号），安全性靠「上游密钥验签」保证，
    // 验签在 WalletService → WalletGateway::verifyCallback 里做，验不过直接拒。
    Route::prefix('wallet/callback')->middleware(['throttle:120,1'])->group(function () {
        Route::post('recharge', [WalletCallbackController::class, 'recharge'])
            ->name('api.telegram.wallet.recharge.callback');

        Route::post('withdraw', [WalletCallbackController::class, 'withdraw'])
            ->name('api.telegram.wallet.withdraw.callback');
    });

    // Mini App 接口：H5 页面调用。
    // identity 只认 Telegram 校验过的 initData（telegram.initdata 中间件），
    // 客户端传 member_id / user_id 一律无效，杜绝伪造身份下单。
    Route::prefix('wallet/miniapp')->middleware(['telegram.initdata', 'throttle:60,1'])->group(function () {
        Route::get('balance', [WalletMiniAppController::class, 'balance']);
        Route::post('recharge', [WalletMiniAppController::class, 'recharge']);
        Route::post('withdraw', [WalletMiniAppController::class, 'withdraw']);
        Route::get('orders', [WalletMiniAppController::class, 'orders']);
    });
});
