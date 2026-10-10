<?php

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
use Illuminate\Support\Facades\DB;

class Wallet extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'wallets';

    /**
     * 注意：余额类字段（balance / frozen_balance / total_*）刻意不在 fillable 里。
     *
     * 钱只能走 addBalance / reduceBalance / freeze / unfreeze / deductFrozen
     * 这几个带行锁 + 写流水的方法，任何 create/update 批量赋值都改不了余额，
     * 从模型层堵死「绕过接口直接改钱包余额」。
     */
    protected $fillable = [
        'member_id', 'currency', 'status', 'remark',
        // CatchAdmin 的 BaseOperate 只在这两个字段进了 fillable 时才写时间戳，
        // 不列进来 created_at / updated_at 恒为 0（列表里显示 1970-01-01）。
        'created_at', 'updated_at'
    ];

    protected $casts = [
        'balance' => 'decimal:8',
        'frozen_balance' => 'decimal:8',
        'total_recharge' => 'decimal:8',
        'total_withdraw' => 'decimal:8',
    ];

    /**
     * 状态常量
     */
    const STATUS_DISABLED = 0; // 禁用
    const STATUS_NORMAL = 1;   // 正常

    /**
     * 关联会员
     */
    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /**
     * 关联账本记录
     */
    public function ledgers()
    {
        return $this->hasMany(Ledger::class, 'wallet_id');
    }

    /**
     * 增加余额（加钱）
     * @param float $amount 金额
     * @param string $type 类型
     * @param string|null $orderNo 订单号
     * @param array $extra 额外信息
     * @return bool
     * @throws \Throwable
     */
    public function addBalance(float $amount, string $type, string $orderNo = null, array $extra = []): bool
    {
        return DB::transaction(function () use ($amount, $type, $orderNo, $extra) {
            // 锁定钱包记录
            $wallet = self::where('id', $this->id)->lockForUpdate()->first();

            $balanceBefore = $wallet->balance;
            $wallet->balance += $amount;
            $balanceAfter = $wallet->balance;

            // 更新累计充值
            if ($type === 'recharge') {
                $wallet->total_recharge += $amount;
            }

            if (!$wallet->save()) {
                return false;
            }

            // 记录账本
            Ledger::create([
                'member_id' => $this->member_id,
                'wallet_id' => $this->id,
                'order_no' => $orderNo,
                'type' => $type,
                'currency' => $this->currency,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'frozen_before' => $wallet->frozen_balance,
                'frozen_after' => $wallet->frozen_balance,
                'title' => $extra['title'] ?? '余额增加',
                'description' => $extra['description'] ?? null,
                'remark' => $extra['remark'] ?? null,
                'operator_id' => $extra['operator_id'] ?? 0,
                'operator_type' => $extra['operator_type'] ?? 'system',
                'ip' => $extra['ip'] ?? null,
            ]);

            return true;
        });
    }

    /**
     * 减少余额（扣钱）
     * @param float $amount 金额
     * @param string $type 类型
     * @param string|null $orderNo 订单号
     * @param array $extra 额外信息
     * @return bool
     * @throws \Throwable
     */
    public function reduceBalance(float $amount, string $type, string $orderNo = null, array $extra = []): bool
    {
        return DB::transaction(function () use ($amount, $type, $orderNo, $extra) {
            // 锁定钱包记录
            $wallet = self::where('id', $this->id)->lockForUpdate()->first();

            // 检查余额是否足够
            if ($wallet->balance < $amount) {
                throw new \Exception('余额不足');
            }

            $balanceBefore = $wallet->balance;
            $wallet->balance -= $amount;
            $balanceAfter = $wallet->balance;

            // 更新累计提现
            if ($type === 'withdraw') {
                $wallet->total_withdraw += $amount;
            }

            if (!$wallet->save()) {
                return false;
            }

            // 记录账本（扣款记为负数）
            Ledger::create([
                'member_id' => $this->member_id,
                'wallet_id' => $this->id,
                'order_no' => $orderNo,
                'type' => $type,
                'currency' => $this->currency,
                'amount' => -$amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'frozen_before' => $wallet->frozen_balance,
                'frozen_after' => $wallet->frozen_balance,
                'title' => $extra['title'] ?? '余额减少',
                'description' => $extra['description'] ?? null,
                'remark' => $extra['remark'] ?? null,
                'operator_id' => $extra['operator_id'] ?? 0,
                'operator_type' => $extra['operator_type'] ?? 'system',
                'ip' => $extra['ip'] ?? null,
            ]);

            return true;
        });
    }

    /**
     * 冻结余额
     * @param float $amount 金额
     * @return bool
     * @throws \Throwable
     */
    public function freeze(float $amount, string $orderNo = null, array $extra = []): bool
    {
        return DB::transaction(function () use ($amount, $orderNo, $extra) {
            $wallet = self::where('id', $this->id)->lockForUpdate()->first();

            if ($wallet->balance < $amount) {
                throw new \Exception('可用余额不足');
            }

            $balanceBefore = $wallet->balance;
            $frozenBefore = $wallet->frozen_balance;

            $wallet->balance -= $amount;
            $wallet->frozen_balance += $amount;

            if (!$wallet->save()) {
                return false;
            }

            // 原来冻结完全不写账本，提现下单冻结的这笔在流水里查不到，
            // 导致 LedgerService 汇总与钱包余额对不上。
            // 冻结只是「可用 → 冻结」的内部转移，总权益不变，
            // 所以 amount 记 0（不污染收支统计），但完整记录前后快照。
            Ledger::create([
                'member_id' => $this->member_id,
                'wallet_id' => $this->id,
                'order_no' => $orderNo,
                'type' => Ledger::TYPE_FREEZE,
                'currency' => $this->currency,
                'amount' => 0,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->balance,
                'frozen_before' => $frozenBefore,
                'frozen_after' => $wallet->frozen_balance,
                'title' => $extra['title'] ?? '余额冻结',
                'description' => $extra['description'] ?? null,
                'remark' => $extra['remark'] ?? null,
                'operator_id' => $extra['operator_id'] ?? 0,
                'operator_type' => $extra['operator_type'] ?? 'system',
                'ip' => $extra['ip'] ?? null,
            ]);

            return true;
        });
    }

    /**
     * 解冻余额
     * @param float $amount 金额
     * @return bool
     * @throws \Throwable
     */
    public function unfreeze(float $amount, string $orderNo = null, array $extra = []): bool
    {
        return DB::transaction(function () use ($amount, $orderNo, $extra) {
            $wallet = self::where('id', $this->id)->lockForUpdate()->first();

            if ($wallet->frozen_balance < $amount) {
                throw new \Exception('冻结余额不足');
            }

            $balanceBefore = $wallet->balance;
            $frozenBefore = $wallet->frozen_balance;

            $wallet->frozen_balance -= $amount;
            $wallet->balance += $amount;

            if (!$wallet->save()) {
                return false;
            }

            // 同 freeze：解冻也必须留痕，否则提现取消的这笔在账本里查不到
            Ledger::create([
                'member_id' => $this->member_id,
                'wallet_id' => $this->id,
                'order_no' => $orderNo,
                'type' => Ledger::TYPE_UNFREEZE,
                'currency' => $this->currency,
                'amount' => 0,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->balance,
                'frozen_before' => $frozenBefore,
                'frozen_after' => $wallet->frozen_balance,
                'title' => $extra['title'] ?? '冻结释放',
                'description' => $extra['description'] ?? null,
                'remark' => $extra['remark'] ?? null,
                'operator_id' => $extra['operator_id'] ?? 0,
                'operator_type' => $extra['operator_type'] ?? 'system',
                'ip' => $extra['ip'] ?? null,
            ]);

            return true;
        });
    }

    /**
     * 扣除冻结余额（用于提现完成）
     * @param float $amount 金额
     * @param string $type 类型
     * @param string|null $orderNo 订单号
     * @param array $extra 额外信息
     * @return bool
     * @throws \Throwable
     */
    public function deductFrozen(float $amount, string $type, string $orderNo = null, array $extra = []): bool
    {
        return DB::transaction(function () use ($amount, $type, $orderNo, $extra) {
            $wallet = self::where('id', $this->id)->lockForUpdate()->first();

            if ($wallet->frozen_balance < $amount) {
                throw new \Exception('冻结余额不足');
            }

            $frozenBefore = $wallet->frozen_balance;
            $wallet->frozen_balance -= $amount;
            $frozenAfter = $wallet->frozen_balance;

            if ($type === 'withdraw') {
                $wallet->total_withdraw += $amount;
            }

            if (!$wallet->save()) {
                return false;
            }

            // 记录账本
            Ledger::create([
                'member_id' => $this->member_id,
                'wallet_id' => $this->id,
                'order_no' => $orderNo,
                'type' => $type,
                'currency' => $this->currency,
                'amount' => -$amount,
                'balance_before' => $wallet->balance,
                'balance_after' => $wallet->balance,
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'title' => $extra['title'] ?? '冻结余额扣除',
                'description' => $extra['description'] ?? null,
                'remark' => $extra['remark'] ?? null,
                'operator_id' => $extra['operator_id'] ?? 0,
                'operator_type' => $extra['operator_type'] ?? 'system',
                'ip' => $extra['ip'] ?? null,
            ]);

            return true;
        });
    }
}
