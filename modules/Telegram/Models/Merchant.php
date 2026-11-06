<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;
use Modules\Permissions\Models\Traits\DataRange;

/**
 * 平台商户 / 租户。tenant_id = 0 是平台侧，不是本表的一条记录。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property bool $status
 */
class Merchant extends Model
{
    use DataRange;

    protected $table = 'merchants';

    protected $fillable = [
        'id', 'name', 'code', 'status', 'contact',
        'creator_id', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected array $fields = [
        'id', 'name', 'code', 'status', 'contact', 'created_at', 'updated_at',
    ];

    protected array $form = ['name', 'code', 'status', 'contact'];

    public array $searchable = [
        'name' => 'like',
        'code' => 'like',
    ];

    // 顶栏「归属商户」下拉要一次拿到全量，不做分页
    protected bool $isPaginate = false;
}
