<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

/**
 * 套餐订单：余额扣款购买套餐的凭证，同时记录续期锚点与结果。
 *
 * @property int $id
 * @property string $order_no
 * @property int $merchant_id
 * @property int $user_id
 * @property int $plan_id
 * @property string $plan_name
 * @property int $plan_days
 * @property string $price
 * @property int $status
 * @property int|null $paid_at
 * @property int|null $expired_from
 * @property int|null $expired_to
 * @property string|null $remark
 */
class PlanOrder extends Model
{
    public const STATUS_PENDING = 0;

    public const STATUS_PAID = 1;

    public const STATUS_CANCELED = 2;

    protected $table = 'plan_orders';

    protected $casts = [
        'price' => 'decimal:8',
        'paid_at' => 'datetime',
    ];

    protected $fillable = [
        'id', 'order_no', 'merchant_id', 'user_id', 'plan_id', 'plan_name', 'plan_days',
        'price', 'status', 'paid_at', 'expired_from', 'expired_to', 'remark',
        'creator_id', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected array $fields = [
        'id', 'order_no', 'merchant_id', 'user_id', 'plan_id', 'plan_name', 'plan_days',
        'price', 'status', 'paid_at', 'expired_from', 'expired_to', 'created_at',
    ];
}
