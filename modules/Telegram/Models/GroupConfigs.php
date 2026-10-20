<?php
declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property int $groupId
 * @property string|null $mid
 * @property string|null $customers
 * @property string|null $welcome
 * @property string|null $autoReply
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read BotGroups $botGroup
 */
class GroupConfigs extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'group_configs';

    /**
     * 单群配置的缓存时间（分钟）
     *
     * 商户ID、欢迎语都是低频改动、高频读取，缓存几分钟即可；
     * 后台一保存就走 saved 钩子精准清掉，不存在「改完要等缓存」的问题。
     */
    public const CONFIG_CACHE_TTL = 600;

    protected $fillable = [
        'id',
        'groupId',
        'mid',
        'customers',
        'welcome',
        'autoReply',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected array $fields = [
        'id',
        'groupId',
        'mid',
        'customers',
        'welcome',
        'autoReply',
        'created_at',
        'updated_at',
    ];

    protected array $form = [
        'groupId',
        'mid',
        'customers',
        'welcome',
        'autoReply',
    ];

    public array $searchable = [
        'groupId' => '=',
        'mid' => 'like',
    ];

    protected bool $isPaginate = true;

    // 关联关系
    public function botGroup(): BelongsTo
    {
        return $this->belongsTo(BotGroups::class, 'groupId', 'id');
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function ($model) {
            static::refreshCache($model);
            static::forgetCacheForChat(static::chatIdOf($model));
        });

        static::deleted(function ($model) {
            static::refreshCache($model);
            static::forgetCacheForChat(static::chatIdOf($model));
        });
    }

    /**
     * 配置所属群的 chat_id（配置挂在 bot_groups 上，运行时只有 chat_id）
     */
    protected static function chatIdOf(?GroupConfigs $groupConfig): int|string|null
    {
        return $groupConfig?->botGroup?->chat_id;
    }

    /**
     * 单群配置的缓存键
     *
     * 原来只有一份 Cache('group_configs') 大数组，两个写入方格式还不一致
     * （BotGroups 存单条、GroupConfigs::refreshCache 存列表），读的人很容易踩空。
     * 运行时按 chat_id 单独缓存一份，格式稳定，改配置时精准失效。
     */
    public static function configCacheKey(int|string|null $chatId): string
    {
        return 'telegram:group:config:' . (string) $chatId;
    }

    public static function forgetCacheForChat(int|string|null $chatId): void
    {
        if ($chatId === null || $chatId === '') {
            return;
        }

        Cache::forget(self::configCacheKey($chatId));
    }

    /**
     * 按 chat_id 取群配置（带缓存）
     *
     * 命中不了配置时返回 null，调用方自行判空。
     */
    public static function forChat(int|string|null $chatId): ?self
    {
        $key = $chatId === null ? '' : trim((string) $chatId);

        if ($key === '') {
            return null;
        }

        return Cache::remember(
            self::configCacheKey($key),
            now()->addMinutes(self::CONFIG_CACHE_TTL),
            fn () => static::query()
                ->whereHas('botGroup', fn ($q) => $q->where('chat_id', $key))
                ->first()
        );
    }

    /**
     * 群里配的商户ID
     *
     * 它是这个群的「默认商户号」：没用过 /bm 绑定时，功能里的
     * {{merchant_id}} / $ctx->value('merchant_id') 取的就是它。
     * 绑过（feature_data 里有值）以绑定为准，这里只做兜底。
     */
    public static function merchantIdOf(int|string|null $chatId): ?string
    {
        $mid = trim((string) (static::forChat($chatId)?->mid ?? ''));

        return $mid === '' ? null : $mid;
    }

    /**
     * 进群欢迎语（录入的是中文，发送时按群语种补一段对应语种）
     */
    public static function welcomeOf(int|string|null $chatId): string
    {
        return trim((string) (static::forChat($chatId)?->welcome ?? ''));
    }

    public static function refreshCache(?GroupConfigs $groupConfig = null): void
    {
        $groupConfig = $groupConfig ?: static::latest()->first();

        if (!$groupConfig || !$group = $groupConfig->botGroup) {
            return;
        }

        $chatId = $group->chat_id;
        $configs = static::where('groupId', $group->id)->get();

        $cacheData = Cache::get('group_configs', []);
        $cacheData[$chatId] = $configs->toArray();

        Cache::forever('group_configs', $cacheData);
    }
}
