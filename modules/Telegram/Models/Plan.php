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
 * @property int $status
 * @property int $sort
 * @property string|null $remark
 */
class Plan extends Model
{
    protected $table = 'plans';

    protected $casts = [
        'price' => 'decimal:8',
        // 不能用 'bool'：编辑弹窗的 el-switch 绑定的是 0/1（active-value 严格
        // 比较 true !== 1），cast 成 bool 后详情回显 true，开关永远显示关闭
        'status' => 'integer',
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
