<?php
declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $chat_id
 * @property int $user_id
 * @property string|null $username
 * @property string $status
 * @property bool $can_delete_messages
 * @property bool $can_invite_users
 * @property bool $can_promote_members
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read BotGroups|null $botGroup
 */
class GroupAdmins extends Model
{
    protected $table = 'group_admins';

    protected $fillable = [
        'id',
        'group_id',
        'chat_id',
        'user_id',
        'username',
        'status',
        'can_delete_messages',
        'can_invite_users',
        'can_promote_members',
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
        'can_delete_messages',
        'can_invite_users',
        'can_promote_members',
        'created_at',
        'updated_at',
    ];

    protected array $form = [
        'group_id',
        'chat_id',
        'user_id',
        'username',
        'status',
        'can_delete_messages',
        'can_invite_users',
        'can_promote_members',
    ];

    public array $searchable = [
        'chat_id' => '=',
        'user_id' => '=',
        'username' => 'like',
        'status' => '=',
    ];

    protected bool $isPaginate = true;

    protected $casts = [
        'can_delete_messages' => 'boolean',
        'can_invite_users' => 'boolean',
        'can_promote_members' => 'boolean',
    ];

    // 关联关系
    public function botGroup(): BelongsTo
    {
        return $this->belongsTo(BotGroups::class, 'group_id', 'id');
    }
}
