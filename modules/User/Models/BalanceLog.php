<?php

declare(strict_types=1);

namespace Modules\User\Models;

use Catch\Base\CatchModel as Model;

/**
 * 账号余额台账：每一次余额变动都必须有一条记录（购买套餐 / 管理员调账）。
 *
 * @property int $id
 * @property int $user_id
 * @property string $delta
 * @property string $balance_after
 * @property string $type
 * @property int|null $order_id
 * @property string|null $remark
 */
class BalanceLog extends Model
{
    protected $table = 'balance_logs';

    protected $casts = [
        'delta' => 'decimal:8',
        'balance_after' => 'decimal:8',
    ];

    protected $fillable = [
        'id', 'user_id', 'delta', 'balance_after', 'type', 'order_id', 'remark',
        'creator_id', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected array $fields = [
        'id', 'user_id', 'delta', 'balance_after', 'type', 'order_id', 'remark', 'creator_id', 'created_at',
    ];
}
