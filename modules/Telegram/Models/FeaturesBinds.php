<?php
declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Telegram\Models\ThirdApiConfig;

/**
 * @property int $id
 * @property int $bot_id
 * @property string|null $chat_id
 * @property int $feature_id
 * @property int|null $third_config_id 该(实体,功能)绑定的上游配置
 * @property string|null $config
 * @property bool $enabled
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read Features $feature
 * @property-read Bots|null $bot
 * @property-read BotGroups|null $botGroup
 * @property-read ThirdApiConfig|null $thirdConfig
 */
class FeaturesBinds extends Model
{
    use DataRange;

    /**
     * 「不针对具体群」的 chat_id
     *
     * 机器人级绑定（给机器人分配功能白名单）用它，等价于以前的 NULL。
     * 必须是 0 而不是 NULL：(bot_id, chat_id, feature_id) 的唯一索引
     * 对 NULL 不生效，用 NULL 就能插出一堆重复行。
     */
    public const ENTITY_WIDE_CHAT_ID = 0;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'features_binds';

    protected $fillable = [
        'id',
        'bot_id',
        'chat_id',
        'feature_id',
        'third_config_id',
        'config',
        'enabled',
        'creator_id',
        'created_at',
        'updated_at',
    ];

    protected array $fields = [
        'id',
        'bot_id',
        'chat_id',
        'feature_id',
        'third_config_id',
        'config',
        'enabled',
        'created_at',
        'updated_at',
    ];

    protected array $form = [
        'bot_id',
        'chat_id',
        'feature_id',
        'third_config_id',
        'config',
        'enabled',
    ];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
    ];

    public array $searchable = [
        'bot_id' => '=',
        'chat_id' => '=',
        'feature_id' => '=',
        'enabled' => '=',
    ];

    protected bool $isPaginate = true;

    // 关联关系
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Features::class, 'feature_id', 'id');
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bots::class, 'bot_id', 'id');
    }

    public function botGroup(): BelongsTo
    {
        return $this->belongsTo(BotGroups::class, 'chat_id', 'chat_id');
    }

    /**
     * 是否群/频道会话
     *
     * Telegram 里群和频道的 chat_id 是负数（超级群/频道 -100xxxxxxxxx，
     * 老式群 -1234567），私聊等于用户 id，恒为正。
     */
    public static function isGroupChat(int|string|null $chatId): bool
    {
        return is_numeric($chatId) && (int) $chatId < 0;
    }

    /**
     * 某个会话真正生效的功能绑定（递进关系的落点）
     *
     * 机器人「配置功能」只是「这个机器人开通了哪些」的范围，
     * 群里必须再勾一遍才真的能用：
     *   群里勾了 → 只看这个群自己勾的
     *   群没勾   → 这个群一个功能都没有，不会因为机器人配了就自动全给
     *
     * 私聊不是群，没有「给这个用户单独配」的场景，沿用机器人级白名单。
     *
     * 以前这里往机器人级兜底，结果就是「群里什么都勾不到，
     * 但因为机器人配了，两个群实际都跑着全部功能」。
     */
    public static function bindsForSession(int $botId, int|string|null $chatId): Collection
    {
        if ($chatId === null || $chatId === '') {
            return static::query()
                ->where('bot_id', $botId)
                ->where('chat_id', static::ENTITY_WIDE_CHAT_ID)
                ->get();
        }

        if (! static::isGroupChat($chatId)) {
            return static::query()
                ->where('bot_id', $botId)
                ->where('chat_id', static::ENTITY_WIDE_CHAT_ID)
                ->get();
        }

        return static::query()
            ->where('bot_id', $botId)
            ->where('chat_id', (int) $chatId)
            ->get();
    }

    /**
     * 取会话上某个功能的绑定
     */
    public static function bindForSession(int $botId, int|string|null $chatId, int $featureId): ?self
    {
        return static::bindsForSession($botId, $chatId)
            ->firstWhere('feature_id', $featureId);
    }

    /**
     * 该绑定指定的上游配置（绑定级，覆盖实体级默认）
     */
    public function thirdConfig(): BelongsTo
    {
        return $this->belongsTo(ThirdApiConfig::class, 'third_config_id', 'id');
    }
}
