<?php

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;

class Ledger extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'ledgers';

    /**
     * 关闭 Laravel 自动时间戳：CatchAdmin 的 createdAt() 宏建的是 int(10) 列，
     * 交给框架自动填会写入 Carbon 对象导致类型错误。
     */
    public $timestamps = false;

    /**
     * 但要自己补上时间戳。
     * 原来只关了 $timestamps 却没有任何填充逻辑 → created_at 恒为 0，
     * 导致 LedgerService 里所有 whereBetween('created_at', ...) 全部查不到数据。
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $ledger) {
            $now = time();

            if (empty($ledger->created_at)) {
                $ledger->created_at = $now;
            }

            if (empty($ledger->updated_at)) {
                $ledger->updated_at = $now;
            }
        });
    }

    protected $fillable = [
        'member_id', 'wallet_id', 'order_no', 'type', 'currency',
        'amount', 'balance_before', 'balance_after', 'frozen_before',
        'frozen_after', 'related_type', 'related_id', 'title',
        'description', 'remark', 'operator_id', 'operator_type', 'ip'
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'balance_before' => 'decimal:8',
        'balance_after' => 'decimal:8',
        'frozen_before' => 'decimal:8',
        'frozen_after' => 'decimal:8',
    ];

    /**
     * 类型常量
     */
    const TYPE_RECHARGE = 'recharge';  // 充值
    const TYPE_WITHDRAW = 'withdraw';  // 提现
    const TYPE_TRANSFER = 'transfer';  // 转账
    const TYPE_REFUND = 'refund';      // 退款
    const TYPE_FEE = 'fee';            // 手续费
    const TYPE_REWARD = 'reward';      // 奖励
    const TYPE_DEDUCT = 'deduct';      // 扣款
    const TYPE_FREEZE = 'freeze';      // 冻结
    const TYPE_UNFREEZE = 'unfreeze';  // 解冻

    /**
     * 类型文本映射
     */
    public static array $typeTexts = [
        self::TYPE_RECHARGE => '充值',
        self::TYPE_WITHDRAW => '提现',
        self::TYPE_TRANSFER => '转账',
        self::TYPE_REFUND => '退款',
        self::TYPE_FEE => '手续费',
        self::TYPE_REWARD => '奖励',
        self::TYPE_DEDUCT => '扣款',
        self::TYPE_FREEZE => '冻结',
        self::TYPE_UNFREEZE => '解冻',
    ];

    /**
     * 关联会员
     */
    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /**
     * 关联钱包
     */
    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    /**
     * 获取类型文本
     */
    public function getTypeTextAttribute(): string
    {
        return self::$typeTexts[$this->type] ?? $this->type;
    }

    /**
     * 是否收入
     */
    public function isIncome(): bool
    {
        return $this->amount > 0;
    }

    /**
     * 是否支出
     */
    public function isExpense(): bool
    {
        return $this->amount < 0;
    }
}

