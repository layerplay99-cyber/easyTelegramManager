<?php

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;

class TransactionLimit extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'transaction_limits';

    protected $fillable = [
        'name', 'type', 'level', 'currency', 'min_amount', 'max_amount',
        'daily_amount', 'daily_count', 'monthly_amount', 'monthly_count',
        'status', 'remark'
    ];

    protected $casts = [
        'min_amount' => 'decimal:8',
        'max_amount' => 'decimal:8',
        'daily_amount' => 'decimal:8',
        'monthly_amount' => 'decimal:8',
    ];

    /**
     * 获取限额配置
     * @param string $type recharge/withdraw
     * @param string $level 会员等级
     * @param string $currency 币种
     * @return TransactionLimit|null
     */
    public static function getLimitConfig(string $type, string $level = 'default', string $currency = 'USDT'): ?TransactionLimit
    {
        return self::where('type', $type)
            ->where('level', $level)
            ->where('currency', $currency)
            ->where('status', 1)
            ->first();
    }

    /**
     * 检查金额是否在限额内
     */
    public function checkAmount(float $amount): bool
    {
        if ($this->min_amount > 0 && $amount < $this->min_amount) {
            return false;
        }
        if ($this->max_amount > 0 && $amount > $this->max_amount) {
            return false;
        }
        return true;
    }

    /**
     * 获取限额描述
     */
    public function getDescription(): string
    {
        $desc = [];
        if ($this->min_amount > 0 || $this->max_amount > 0) {
            $desc[] = "单笔: {$this->min_amount} - {$this->max_amount}";
        }
        if ($this->daily_amount > 0) {
            $desc[] = "日限额: {$this->daily_amount}";
        }
        if ($this->daily_count > 0) {
            $desc[] = "日次数: {$this->daily_count}";
        }
        return implode(', ', $desc);
    }
}
