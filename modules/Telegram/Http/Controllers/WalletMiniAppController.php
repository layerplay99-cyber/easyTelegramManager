<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Telegram\Services\WalletService;
use Throwable;

/**
 * Mini App（Telegram 内置浏览器）钱包接口
 *
 * 给 H5 页面调用：查余额 / 充值 / 提现 / 查订单。
 *
 * 安全边界：
 *   1. 路由挂 telegram.initdata 中间件，身份只认 Telegram 校验过的 initData，
 *      客户端传的 member_id / user_id 一律忽略（伪造成别人来下单是无效的）；
 *   2. 请求只接受「金额 + 币种 + 业务标识」，没有任何能直接改余额的字段；
 *   3. 必带 nonce + timestamp 防重放，可选 idempotency_key 做幂等；
 *   4. 上游由后台在功能配置里选（features.config.upstream_id），不在前端传。
 */
class WalletMiniAppController extends BaseController
{
    public function __construct(protected WalletService $walletService)
    {
    }

    /**
     * 当前用户身份（来自 initData）
     */
    protected function telegramUserId(Request $request): int
    {
        return (int) $request->attributes->get('telegram_user_id');
    }

    /**
     * 余额
     */
    public function balance(Request $request): JsonResponse
    {
        try {
            $member = $this->walletService->resolveMember($this->telegramUserId($request));

            return $this->jsonSuccess(
                $this->walletService->balance($member, (string) $request->input('currency', 'CNY'))
            );
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 充值下单：返回支付链接
     */
    public function recharge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'feature' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|max:10',
            'idempotency_key' => 'nullable|string|max:100',
            'nonce' => 'required|string|max:50',
            'timestamp' => 'required|integer',
        ]);

        try {
            $member = $this->walletService->resolveMember($this->telegramUserId($request));
            $upstream = $this->walletService->upstreamFor($data['feature']);

            return $this->jsonSuccess($this->walletService->createRecharge(
                $member,
                $data['currency'] ?? 'CNY',
                (float) $data['amount'],
                $upstream,
                $data
            ));
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 提现下单：先冻结余额再发上游
     */
    public function withdraw(Request $request): JsonResponse
    {
        $data = $request->validate([
            'feature' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|max:10',
            'idempotency_key' => 'nullable|string|max:100',
            'nonce' => 'required|string|max:50',
            'timestamp' => 'required|integer',
            'withdraw_method' => 'nullable|string|max:50',
            'withdraw_info' => 'nullable|array',
        ]);

        try {
            $member = $this->walletService->resolveMember($this->telegramUserId($request));
            $upstream = $this->walletService->upstreamFor($data['feature']);

            return $this->jsonSuccess($this->walletService->createWithdraw(
                $member,
                $data['currency'] ?? 'CNY',
                (float) $data['amount'],
                $upstream,
                $data
            ));
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 查订单：带 order_no 查单条，否则返回最近 20 条
     */
    public function orders(Request $request): JsonResponse
    {
        try {
            $member = $this->walletService->resolveMember($this->telegramUserId($request));
            $orderNo = $request->input('order_no');

            if ($orderNo) {
                return $this->jsonSuccess($this->walletService->findOrder((string) $orderNo, $member->id));
            }

            return $this->jsonSuccess($this->walletService->recentOrders($member->id, 20));
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage());
        }
    }
}
