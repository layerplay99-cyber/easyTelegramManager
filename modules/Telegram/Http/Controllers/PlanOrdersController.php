<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Exceptions\FailedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Permissions\Support\MerchantContext;
use Modules\Telegram\Models\Merchant;
use Modules\Telegram\Models\Plan;
use Modules\Telegram\Models\PlanOrder;
use Modules\Telegram\Services\PlanPurchaseService;
use Modules\User\Models\User;

/**
 * 套餐订单：商家自助购买（余额扣款）+ 平台超管调账/查看全部
 *
 * 数据范围：
 *   - index：平台超管看全部；其它用户只看自己名下的订单
 *   - purchase：当前登录账号为购买人，续期对象是其归属商户（平台侧账号延长自身）
 *   - adjust-balance：仅平台超管
 */
class PlanOrdersController extends Controller
{
    public function __construct(
        protected readonly PlanOrder $model,
        protected readonly PlanPurchaseService $purchaseService,
    ) {
    }

    public function index(Request $request): mixed
    {
        if (MerchantContext::isPlatformSuperAdmin()) {
            return $this->model->getList();
        }

        $user = $this->currentUser();

        return PlanOrder::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate((int) $request->input('limit', 15));
    }

    /**
     * 我的余额与有效期（购买页顶部展示）
     */
    public function balance(): array
    {
        $user = $this->currentUser();
        $merchant = (int) $user->tenant_id > 0
            ? Merchant::query()->find($user->tenant_id)
            : null;

        return [
            'balance' => (string) $user->balance,
            'expired_at' => $merchant?->expired_at?->toDateTimeString()
                ?? $user->expired_at?->toDateTimeString(),
            'merchant_id' => (int) $user->tenant_id,
            'merchant_name' => $merchant?->name ?? '-',
            // 前端据此决定是否显示「调账」按钮（调账只给平台超管）
            'is_super_admin' => MerchantContext::isPlatformSuperAdmin(),
        ];
    }

    /**
     * 可调账账号的远程搜索（平台超管调账弹窗用）
     *
     * 只返回必要字段：账号 id / 名称 / 所属商户 / 当前余额。
     * 商户与账号是「users.tenant_id」关联，不额外建表。
     */
    public function accounts(Request $request): array
    {
        if (! MerchantContext::isPlatformSuperAdmin()) {
            throw new FailedException('无权查看账号');
        }

        $keyword = trim((string) $request->input('keyword', ''));

        $query = User::query()->orderBy('id');

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                // users 表的账号列是 username（没有 name 列）
                $q->where('username', 'like', '%' . $keyword . '%')
                    ->orWhere('email', 'like', '%' . $keyword . '%')
                    // 直接贴账号 ID 也能搜到
                    ->orWhere('id', (int) $keyword);
            });
        }

        $users = $query->limit(20)->get(['id', 'username', 'email', 'tenant_id', 'balance']);

        $merchantNames = Merchant::query()
            ->whereIn('id', $users->pluck('tenant_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return $users->map(fn (User $u) => [
            'id' => (int) $u->id,
            'name' => (string) $u->username,
            'email' => (string) $u->email,
            'merchant_id' => (int) $u->tenant_id,
            'merchant_name' => $merchantNames[(int) $u->tenant_id] ?? '-',
            'balance' => (string) $u->balance,
        ])->all();
    }

    public function purchase(Request $request): mixed
    {
        $user = $this->currentUser();
        $planId = (int) $request->input('plan_id', 0);

        if ($planId <= 0) {
            throw new FailedException('请选择套餐');
        }

        $plan = Plan::query()->find($planId);

        if (! $plan) {
            throw new FailedException('套餐不存在');
        }

        $order = $this->purchaseService->purchase($user, $plan, (string) $request->input('remark', ''));

        return [
            'order' => $order,
            'balance' => (string) User::query()->whereKey($user->id)->value('balance'),
        ];
    }

    /**
     * 平台超管调账（充值/扣减余额），必须写备注
     */
    public function adjustBalance(Request $request): mixed
    {
        if (! MerchantContext::isPlatformSuperAdmin()) {
            throw new FailedException('无权调账');
        }

        $userId = (int) $request->input('user_id', 0);
        $amount = (string) $request->input('amount', '0');
        $remark = trim((string) $request->input('remark', ''));

        if ($userId <= 0 || ! is_numeric($amount)) {
            throw new FailedException('参数不完整：user_id / amount');
        }

        if ($remark === '') {
            throw new FailedException('调账必须填写备注');
        }

        $target = User::query()->find($userId);

        if (! $target) {
            throw new FailedException('账号不存在');
        }

        $user = $this->purchaseService->adjustBalance($target, $amount, $remark, (int) $this->currentUser()->id);

        return ['balance' => (string) $user->balance];
    }

    private function currentUser(): User
    {
        /** @var User|null $user */
        $user = Auth::guard(getGuardName())->user();

        if (! $user) {
            throw new FailedException('未登录');
        }

        return $user;
    }
}
