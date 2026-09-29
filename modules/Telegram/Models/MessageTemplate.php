<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;

/**
 * 消息模板（结构化 blocks）
 *
 * @property int $id
 * @property string $title
 * @property array $blocks
 * @property string $channel bot|user
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class MessageTemplate extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'message_templates';

    protected $fillable = [
        'id',
        'title',
        'blocks',
        'channel',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    protected array $fields = [
        'id',
        'title',
        'blocks',
        'channel',
        'created_at',
        'updated_at'
    ];

    protected array $form = [
        'title',
        'blocks',
        'channel'
    ];

    protected $casts = [
        'blocks' => 'array',
    ];

    public array $searchable = [
        'title' => 'like',
        'channel' => '=',
    ];

    protected bool $isPaginate = true;
}
