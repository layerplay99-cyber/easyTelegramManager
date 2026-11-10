<?php

declare(strict_types=1);

namespace Modules\Telegram\Services;

use Catch\Exceptions\FailedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Telegram\Models\Merchant;
use Modules\Telegram\Models\Plan;
use Modules\Telegram\Models\PlanOrder;
use Modules\User\Models\BalanceLog;
use Modules\User\Models\User;

/**
 * 套餐购买：余额扣款 → 延长有效期（商户级优先，个人账号延长自身）
 *
 * 续期锚点：new = max(now, 当前到期) + 天数。
 *   - 没过期续费按原到期时间累加（提前续费不亏天数）
 *   - 已过期从当前时间起算
 *
 * 一切金额比较用 bccomp/bcsub（decimal:8 cast 返回字符串，float 运算会丢精度）。
 * 扣款、订单、延期在同一事务里，行锁防并发重复扣款；商户过期缓存由
 * Merchant 的 saved 钩子自动清除，续费后立即恢复功能。
 */
class PlanPurchaseService
{
    /**
     * 用余额购买套餐，返回已支付订单
     */
    public function purchase(User $buyer, Plan $plan, string $remark = ''): PlanOrder
    {
        if (! $plan->status) {
            throw new FailedException('套餐已下架');
        }

        return DB::transaction(function () use ($buyer, $plan, $remark) {
            // 行锁：同一账号并发购买不会双扣
            $user = User::query()->whereKey($buyer->getKey())->lockForUpdate()->first();

            if ($user === null) {
                throw new FailedException('购买账号不存在');
            }

            if (bccomp((string) $user->balance, (string) $plan->price, 8) < 0) {
                throw new FailedException('余额不足，请先充值');
            }

            $order = new PlanOrder();
            $order->forceFill([
                'order_no' => 'PL' . date('YmdHis') . Str::upper(Str::random(6)),
                'merchant_id' => max(0, (int) $user->tenant_id),
                'user_id' => (int) $user->id,
                'plan_id' => (int) $plan->id,
                'plan_name' => $plan->name,
                'plan_days' => (int) $plan->days,
                'price' => $plan->price,
                'status' => PlanOrder::STATUS_PAID,
                'paid_at' => time(),
                'remark' => $remark,
                'creator_id' => (int) $user->id,
            ]);

            [$from, $to] = $this->extendExpiry($user, (int) $plan->days);

            $order->expired_from = $from;
            $order->expired_to = $to;
            $order->save();

            // 扣余额 + 台账（读的是行锁后的最新值）
            $after = bcsub((string) $user->balance, (string) $plan->price, 8);
            $user->forceFill(['balance' => $after])->save();

            BalanceLog::query()->create([
                'user_id' => $user->id,
                'delta' => '-' . $plan->price,
                'balance_after' => $after,
                'type' => 'purchase',
                'order_id' => $order->id,
                'remark' => '购买套餐：' . $plan->name,
                'creator_id' => $user->id,
            ]);

            return $order;
        });
    }

    /**
     * 延长有效期。tenant_id > 0 延长所属商户；平台侧账号延长自身 expired_at。
     *
     * @return array{0: int, 1: int} [锚点, 新到期时间]（Unix 秒）
     */
    private function extendExpiry(User $user, int $days): array
    {
        $target = null;
        $current = 0;

        if ((int) $user->tenant_id > 0) {
            $target = Merchant::query()->lockForUpdate()->find($user->tenant_id);

            if ($target === null) {
                throw new FailedException('归属商户不存在，无法延长有效期');
            }

            $current = $target->expired_at?->getTimestamp() ?? 0;
        } else {
            $current = $user->expired_at?->getTimestamp() ?? 0;
        }

        $anchor = max(time(), $current);
        $to = $anchor + $days * 86400;
        $carbon = Carbon::createFromTimestamp($to);

        if ($target !== null) {
            $target->forceFill(['expired_at' => $carbon])->save();
        } else {
            $user->forceFill(['expired_at' => $carbon])->save();
        }

        return [$anchor, $to];
    }

    /**
     * 管理员调账（充值/扣减），正负均可，结果不允许为负
     */
    public function adjustBalance(User $user, string $delta, string $remark, int $operatorId): User
    {
        if (bccomp($delta, '0', 8) === 0) {
            throw new FailedException('调账金额不能为 0');
        }

        return DB::transaction(function () use ($user, $delta, $remark, $operatorId) {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new FailedException('账号不存在');
            }

            $after = bcadd((string) $locked->balance, $delta, 8);

            if (bccomp($after, '0', 8) < 0) {
                throw new FailedException('调账后余额不能为负');
            }

            $locked->forceFill(['balance' => $after])->save();

            BalanceLog::query()->create([
                'user_id' => $locked->id,
                'delta' => $delta,
                'balance_after' => $after,
                'type' => 'adjust',
                'remark' => $remark,
                'creator_id' => $operatorId,
            ]);

            return $locked;
        });
    }
}
