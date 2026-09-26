<?php

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

class WithdrawOrder extends Model
{
    protected $table = 'withdraw_orders';

    protected $fillable = [
        'order_no', 'member_id', 'channel_id', 'currency', 'amount',
        'fee', 'actual_amount', 'exchange_rate', 'withdraw_method',
        'withdraw_info', 'third_order_no', 'status', 'processed_at',
        'completed_at', 'request_ip', 'request_sign', 'nonce', 'timestamp', 'remark'
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'fee' => 'decimal:8',
        'actual_amount' => 'decimal:8',
        'exchange_rate' => 'decimal:8',
    ];

    /**
     * 订单状态常量（简化版）
     */
    const STATUS_PENDING = 0;      // 待处理
    const STATUS_PROCESSING = 1;   // 处理中
    const STATUS_COMPLETED = 2;    // 已完成
    const STATUS_CANCELLED = 3;    // 已取消
    const STATUS_FAILED = 4;       // 失败

    /**
     * 状态文本映射
     */
    public static array $statusTexts = [
        self::STATUS_PENDING => '待处理',
        self::STATUS_PROCESSING => '处理中',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_CANCELLED => '已取消',
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
        return 'WD' . date('YmdHis') . mt_rand(100000, 999999);
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
     * 是否可以取消
     */
    public function canCancel(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING
        ]);
    }

    /**
     * 获取提现信息（JSON转数组）
     */
    public function getWithdrawInfoAttribute($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    /**
     * 设置提现信息（数组转JSON）
     */
    public function setWithdrawInfoAttribute($value)
    {
        $this->attributes['withdraw_info'] = is_array($value) ? json_encode($value) : $value;
    }
}
