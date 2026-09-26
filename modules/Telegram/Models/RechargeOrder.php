<?php

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

class RechargeOrder extends Model
{
    protected $table = 'recharge_orders';

    protected $fillable = [
        'order_no', 'member_id', 'channel_id', 'currency', 'amount',
        'fee', 'actual_amount', 'pay_method', 'third_order_no', 'pay_info',
        'callback_url', 'status', 'paid_at', 'completed_at', 'expired_at',
        'request_ip', 'request_sign', 'nonce', 'timestamp', 'remark'
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'fee' => 'decimal:8',
        'actual_amount' => 'decimal:8',
    ];

    /**
     * 状态常量
     */
    const STATUS_PENDING = 0;    // 待支付
    const STATUS_PAID = 1;       // 已支付
    const STATUS_COMPLETED = 2;  // 已完成
    const STATUS_CANCELLED = 3;  // 已取消
    const STATUS_TIMEOUT = 4;    // 已超时
    const STATUS_FAILED = 5;     // 失败

    /**
     * 状态文本映射
     */
    public static array $statusTexts = [
        self::STATUS_PENDING => '待支付',
        self::STATUS_PAID => '已支付',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_CANCELLED => '已取消',
        self::STATUS_TIMEOUT => '已超时',
        self::STATUS_FAILED => '失败',
    ];

    /**
     * 关联会员
     */
    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /**
     * 关联支付通道
     */
    public function channel()
    {
        return $this->belongsTo(PaymentChannel::class, 'channel_id');
    }

    /**
     * 生成订单号
     */
    public static function generateOrderNo(): string
    {
        return 'RC' . date('YmdHis') . mt_rand(100000, 999999);
    }

    /**
     * 获取状态文本
     */
    public function getStatusTextAttribute(): string
    {
        return self::$statusTexts[$this->status] ?? '未知';
    }

    /**
     * 是否已完成
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * 是否可以支付
     */
    public function canPay(): bool
    {
        return $this->status === self::STATUS_PENDING &&
               ($this->expired_at === null || now()->lt($this->expired_at));
    }
}

