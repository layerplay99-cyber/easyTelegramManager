<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\WalletService;
use Throwable;

/**
 * 上游回调入口（充值 / 提现）
 *
 * 这是唯一能把「钱」写进钱包的外部入口，因此只做三件事：
 *   1. 找到订单归属的上游实例（third_config_id）；
 *   2. 用该上游的密钥验签（WalletGateway::verifyCallback），验不过直接拒；
 *   3. 交给 WalletService 推进订单（入账 / 完成 / 解冻）。
 *
 * 对外只返回 success / fail，不回显任何内部原因，避免被探测。
 */
class WalletCallbackController extends \Catch\Base\CatchController
{
    public function __construct(protected WalletService $walletService)
    {
    }

    /**
     * 充值回调
     */
    public function recharge(Request $request): JsonResponse
    {
        return $this->handle($request, 'recharge');
    }

    /**
     * 提现回调
     */
    public function withdraw(Request $request): JsonResponse
    {
        return $this->handle($request, 'withdraw');
    }

    protected function handle(Request $request, string $type): JsonResponse
    {
        $payload = $request->all();

        try {
            $upstream = $this->resolveUpstream($payload);

            if (! $upstream) {
                Log::warning("[钱包] {$type} 回调找不到上游", ['payload' => $payload]);

                return response()->json(['code' => 'fail']);
            }

            $ok = $type === 'recharge'
                ? $this->walletService->handleRechargeCallback($upstream, $payload)
                : $this->walletService->handleWithdrawCallback($upstream, $payload);

            return response()->json(['code' => $ok ? 'success' : 'fail']);
        } catch (Throwable $e) {
            Log::error("[钱包] {$type} 回调处理异常", ['error' => $e->getMessage()]);

            return response()->json(['code' => 'fail']);
        }
    }

    /**
     * 按回调里的上游标识定位实例：
     * 优先用 merchant_key（= public_key），其次显式 third_config_id / upstream_id
     */
    protected function resolveUpstream(array $payload): ?ThirdApiConfig
    {
        $key = $payload['merchant_key'] ?? $payload['merchant_id'] ?? null;

        if ($key) {
            $upstream = ThirdApiConfig::query()->where('public_key', $key)->first();

            if ($upstream) {
                return $upstream;
            }
        }

        $id = $payload['third_config_id'] ?? $payload['upstream_id'] ?? null;

        return $id ? ThirdApiConfig::query()->find((int) $id) : null;
    }
}
