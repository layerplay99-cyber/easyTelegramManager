<?php

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

class PaymentChannel extends Model
{
    protected $table = 'payment_channels';

    protected $fillable = [
        'name', 'code', 'type', 'currency', 'method',
        'submit_api', 'query_api', 'merchant_id', 'secret_key', 'extra_config',
        'fee_rate', 'fixed_fee', 'min_amount', 'max_amount',
        'daily_limit', 'daily_count_limit', 'status', 'priority', 'remark'
    ];

    protected $casts = [
        'fee_rate' => 'decimal:4',
        'fixed_fee' => 'decimal:8',
        'min_amount' => 'decimal:8',
        'max_amount' => 'decimal:8',
        'daily_limit' => 'decimal:8',
    ];

    /**
     * 类型常量
     */
    const TYPE_RECHARGE = 'recharge';  // 充值
    const TYPE_WITHDRAW = 'withdraw';  // 提现
    const TYPE_BOTH = 'both';          // 双向

    /**
     * 状态常量
     */
    const STATUS_DISABLED = 0;      // 禁用
    const STATUS_ENABLED = 1;       // 启用
    const STATUS_MAINTENANCE = 2;   // 维护中

    /**
     * 获取额外配置（JSON转数组）
     */
    public function getExtraConfigAttribute($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    /**
     * 设置额外配置（数组转JSON）
     */
    public function setExtraConfigAttribute($value)
    {
        $this->attributes['extra_config'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * 计算手续费
     */
    public function calculateFee(float $amount): float
    {
        $fee = $amount * ($this->fee_rate / 100) + $this->fixed_fee;
        return round($fee, 8);
    }

    /**
     * 检查金额是否在限额内
     */
    public function checkAmountLimit(float $amount): bool
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
     * 是否启用
     */
    public function isEnabled(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }

    /**
     * 获取完整配置（合并独立字段和额外配置）
     */
    public function getFullConfig(): array
    {
        return array_merge([
            'submit_api' => $this->submit_api,
            'query_api' => $this->query_api,
            'merchant_id' => $this->merchant_id,
            'secret_key' => $this->secret_key,
        ], $this->extra_config ?? []);
    }
}
