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
 * @property string|null $replyLang
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

    protected $fillable = [
        'id',
        'groupId',
        'mid',
        'customers',
        'welcome',
        'autoReply',
        'replyLang',
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
        'replyLang',
        'created_at',
        'updated_at',
    ];

    protected array $form = [
        'groupId',
        'mid',
        'customers',
        'welcome',
        'autoReply',
        'replyLang',
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

        static::saved(fn($model) => static::refreshCache($model));
        static::deleted(fn($model) => static::refreshCache($model));
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
