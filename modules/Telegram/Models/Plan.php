<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

/**
 * 套餐：商户/账号购买后按 days 延长有效期。
 *
 * @property int $id
 * @property string $name
 * @property int $days
 * @property string $price
 * @property bool $status
 * @property int $sort
 * @property string|null $remark
 */
class Plan extends Model
{
    protected $table = 'plans';

    protected $casts = [
        'price' => 'decimal:8',
        'status' => 'bool',
    ];

    protected $fillable = [
        'id', 'name', 'days', 'price', 'status', 'sort', 'remark',
        'creator_id', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected array $fields = [
        'id', 'name', 'days', 'price', 'status', 'sort', 'remark', 'created_at', 'updated_at',
    ];

    protected array $form = ['name', 'days', 'price', 'status', 'sort', 'remark'];

    public array $searchable = [
        'name' => 'like',
    ];
}
