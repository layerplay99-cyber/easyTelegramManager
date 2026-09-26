<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property int $bot_id
 * @property int $app_id
 * @property string $name
 * @property string $chat_id
 * @property string $title
 * @property string $type
 * @property string|null $description
 * @property string|null $invite_link
 * @property string|null $settings
 * @property int $group_id
 * @property bool $enabled
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read Bots $bot
 * @property-read TelegramApiUsers|null $telegramApiUser
 * @property-read GroupGroups|null $groupGroup
 * @property-read GroupConfigs|null $groupConfig
 * @property-read \Illuminate\Database\Eloquent\Collection|FeaturesBinds[] $featuresBinds
 * @property-read \Illuminate\Database\Eloquent\Collection|ServicePeoples[] $servicePeoples
 * @property-read \Illuminate\Database\Eloquent\Collection|GroupMembers[] $groupMembers
 */
class BotGroups extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'bot_groups';

    protected $fillable = [
        'id',
        'app_id',
        'bot_id',
        'name',
        'chat_id',
        'title',
        'type',
        'description',
        'invite_link',
        'settings',
        'group_id',
        'enabled',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * @var array
     */
    protected array $fields = [
        'id',
        'app_id',
        'bot_id',
        'name',
        'chat_id',
        'title',
        'type',
        'description',
        'invite_link',
        'settings',
        'group_id',
        'enabled',
        'created_at',
        'updated_at'
    ];

    /**
     * @var array
     */
    protected array $form = [
        'bot_id',
        'app_id',
        'name',
        'chat_id',
        'title',
        'type',
        'settings',
        'group_id',
        'description',
        'invite_link',
        'enabled'
    ];

    /**
     * @var array
     */
    public array $searchable = [
        'bot_id' => '=',
        'app_id' => '=',
        'name' => 'like',
        'title' => 'like',
        'enabled' => '=',

    ];

    protected bool $isPaginate = true;

    // 关联关系 - BelongsTo
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bots::class, 'bot_id', 'id');
    }

    public function telegramApiUser(): BelongsTo
    {
        // app_id 存的是 telegram_api_users.app_id 字符串，不是主键 id
        return $this->belongsTo(TelegramApiUsers::class, 'app_id', 'app_id');
    }

    public function groupGroup(): BelongsTo
    {
        return $this->belongsTo(GroupGroups::class, 'group_id', 'id');
    }

    // 关联关系 - HasOne
    public function groupConfig(): HasOne
    {
        return $this->hasOne(GroupConfigs::class, 'groupId', 'id');
    }

    // 关联关系 - HasMany
    public function featuresBinds(): HasMany
    {
        return $this->hasMany(FeaturesBinds::class, 'chat_id', 'chat_id');
    }

    public function servicePeoples(): HasMany
    {
        return $this->hasMany(ServicePeoples::class, 'group_id', 'id');
    }

    public function groupMembers(): HasMany
    {
        return $this->hasMany(GroupMembers::class, 'group_id', 'id');
    }

    /**
     * 刷新缓存 begin
     */
    protected static function boot()
    {
        parent::boot();

        // 原来每次 saved/deleted 都调 refreshCache() 全表重建。
        // SyncUserGroupService::syncBotGroups() 在循环里逐个 updateOrCreate，
        // 同步 500 个群就会触发 500 次「全表 + 关联」查询，直接把同步拖垮。
        // 改成只增量刷新受影响的那一个 chat_id。
        static::saved(function ($model) {
            static::refreshCacheForChat($model->chat_id);
        });

        static::deleted(function ($model) {
            static::refreshCacheForChat($model->chat_id);
        });
    }

    /**
     * 全量重建缓存（保持原有的公开方法签名，供外部/批量场景调用）
     */
    public static function refreshCache()
    {
        $botGroups = static::with('groupConfig')->get();
        $formatted = $botGroups->mapWithKeys(function ($item) {
            $config = $item->groupConfig ? $item->groupConfig->toArray() : [];
            return [$item->chat_id => $config];
        })->toArray();
        Cache::forever('group_configs', $formatted);
    }

    /**
     * 只刷新单个 chat_id 对应的缓存项
     */
    public static function refreshCacheForChat(?string $chatId): void
    {
        // 缓存还没建过就做一次全量，保证数据完整
        if (! $chatId || ! Cache::has('group_configs')) {
            static::refreshCache();

            return;
        }

        $cache = Cache::get('group_configs', []);

        if (! is_array($cache)) {
            static::refreshCache();

            return;
        }

        $group = static::with('groupConfig')->where('chat_id', $chatId)->first();

        if ($group) {
            $cache[$chatId] = $group->groupConfig ? $group->groupConfig->toArray() : [];
        } else {
            unset($cache[$chatId]);
        }

        Cache::forever('group_configs', $cache);
    }

}
