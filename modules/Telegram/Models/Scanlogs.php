<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Modules\Telegram\Models\TelegramApiUsers;

/**
 * @property int $id
 * @property int $tuser_id
 * @property string $phone
 * @property int $days
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $username
 * @property string|null $bio
 * @property string|null $avatar_path
 * @property bool $is_active
 * @property int $status
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read string|null $avatar_url
 * @property-read TelegramApiUsers|null $tuser
 */
class Scanlogs extends Model
{
    protected $table = 'scanlogs';

    protected $fillable = [
        'id',
        'tuser_id',
        'phone',
        'days',
        'first_name',
        'last_name',
        'username',
        'bio',
        'avatar_path',
        'is_active',
        'status',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    protected array $fields = [
        'id',
        'tuser_id',
        'phone',
        'days',
        'first_name',
        'last_name',
        'username',
        'bio',
        'avatar_path',
        'is_active',
        'status',
        'created_at'
    ];

    protected array $form = [
        'tuser_id',
        'phone',
        'days',
        'first_name',
        'last_name',
        'username',
        'bio',
        'avatar_path',
        'is_active',
        'status'
    ];

    public array $searchable = [
        'phone' => 'like',
        'username' => 'like',
        'days' => '=',
        'is_active' => '=',
    ];

    protected bool $isPaginate = true;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // 访问器 - 使用 PHP 8 Attribute
    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->avatar_path ? Storage::url($this->avatar_path) : null
        );
    }

    // 关联关系
    public function tuser(): BelongsTo
    {
        // tuser_id 原指向 tusers.id；tusers 已并入 telegram_api_users，
        // 该列现指向 telegram_api_users.id（列名保留以兼容历史数据）。
        return $this->belongsTo(TelegramApiUsers::class, 'tuser_id', 'id');
    }
}
