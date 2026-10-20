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

    /**
     * settings 存的是群的附加配置（目前是语种），DB 里是 TEXT，
     * 必须 cast 成数组，否则读写都要自己 json_decode / json_encode。
     */
    protected $casts = [
        'settings' => 'array',
    ];

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
     *
     * ⚠️ 参数必须是 int|string|null，不能写成 ?string：
     * chat_id 在库里是整型，Eloquent 取出来是 int，本文件 declare(strict_types=1)，
     * int 传给 ?string 会直接 TypeError。而它是 saved/deleted 回调里调的，
     * 一抛异常 BotGroups::updateOrCreate() 整个失败，
     * ListenBotInGroupService::syncChatInfo() 只能 return false，
     * 于是「10 分钟同步节流」的缓存标记永远写不进去 ——
     * 表现就是每条消息都重跑一次同步群信息。
     */
    public static function refreshCacheForChat(int|string|null $chatId): void
    {
        $chatId = $chatId === null ? null : (string) $chatId;

        // 语种也缓了一份，群信息变了要一起失效（后台改完语种能立刻生效）
        Cache::forget(self::languageCacheKey($chatId));

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

    /**
     * 群标记的语种（没标记就用默认语种）
     *
     * 存/读都是 settings.language：settings 已 cast 成 array，
     * 但历史数据里可能是 JSON 字符串，这里兼容一下。
     */
    public function language(): string
    {
        $settings = $this->settings;

        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        $language = strtolower(trim((string) (($settings['language'] ?? '') ?: '')));

        return $language !== '' ? $language : self::defaultLanguage();
    }

    /**
     * 按 chat_id 取群语种
     *
     * 带 10 分钟缓存：识图、回复翻译每条消息都要读，不能每条都查库。
     * 后台改语种时会走 saved 钩子把这份缓存清掉。
     */
    public static function languageOf(int|string|null $chatId): string
    {
        $chatId = $chatId === null ? '' : (string) $chatId;

        if ($chatId === '') {
            return self::defaultLanguage();
        }

        return (string) Cache::remember(
            self::languageCacheKey($chatId),
            now()->addMinutes(10),
            fn () => static::query()->where('chat_id', $chatId)->first()?->language()
                ?? self::defaultLanguage()
        );
    }

    public static function defaultLanguage(): string
    {
        return (string) config('telegram.ocr.default_language', 'zh');
    }

    public static function languageCacheKey(?string $chatId): string
    {
        return 'telegram:group:language:' . (string) $chatId;
    }
}
