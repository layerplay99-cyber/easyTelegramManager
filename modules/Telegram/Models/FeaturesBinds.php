<?php
declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
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
     * 该绑定指定的上游配置（绑定级，覆盖实体级默认）
     */
    public function thirdConfig(): BelongsTo
    {
        return $this->belongsTo(ThirdApiConfig::class, 'third_config_id', 'id');
    }
}
