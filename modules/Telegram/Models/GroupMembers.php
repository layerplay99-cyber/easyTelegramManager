<?php
declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $chat_id
 * @property int $user_id
 * @property string|null $username
 * @property string $status
 * @property bool $is_bot
 * @property \Illuminate\Support\Carbon|null $joined_at
 * @property \Illuminate\Support\Carbon|null $left_at
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read BotGroups|null $botGroup
 */
class GroupMembers extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'group_members';

    protected $fillable = [
        'id',
        'group_id',
        'chat_id',
        'user_id',
        'username',
        'status',
        'is_bot',
        'joined_at',
        'left_at',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected array $fields = [
        'id',
        'group_id',
        'chat_id',
        'user_id',
        'username',
        'status',
        'is_bot',
        'joined_at',
        'left_at',
        'created_at',
        'updated_at',
    ];

    protected array $form = [
        'group_id',
        'chat_id',
        'user_id',
        'username',
        'status',
        'is_bot',
    ];

    public array $searchable = [
        'chat_id' => '=',
        'user_id' => '=',
        'username' => 'like',
        'status' => '=',
    ];

    protected bool $isPaginate = true;

    protected $casts = [
        'is_bot' => 'boolean',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    // 关联关系
    public function botGroup(): BelongsTo
    {
        return $this->belongsTo(BotGroups::class, 'group_id', 'id');
    }
}
