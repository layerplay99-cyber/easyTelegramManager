<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 功能推送回调入口（第三块：三方主动推送）
 *
 * 一个 feature 对应一个 token，上游回调 POST /api/hooks/{token}
 * 即可定位到功能，无需为每个业务写死一个路由。
 *
 * @property int $id
 * @property int $feature_id
 * @property int|null $bot_id
 * @property string|null $route_field
 * @property string|null $default_chat_id
 * @property string $token
 * @property string|null $name
 * @property string|null $dedup_field
 * @property bool $enabled
 * @property int $last_fired_at
 */
class FeatureHooks extends Model
{
    protected $table = 'feature_hooks';

    protected $fillable = [
        'id','feature_id','token','name','dedup_field','enabled','last_fired_at',
        // 多机器人：默认用哪个机器人发 + 从报文哪个字段取路由键 + 兜底群
        'bot_id','route_field','default_chat_id',
    ];

    protected $casts = ['enabled' => 'boolean'];

    protected array $fields = [
        'id','feature_id','token','name','dedup_field','enabled','last_fired_at',
        'bot_id','route_field','default_chat_id','created_at',
    ];

    protected array $form = [
        'feature_id','token','name','dedup_field','enabled',
        'bot_id','route_field','default_chat_id',
    ];

    public array $searchable = ['token' => 'like','feature_id' => '='];

    protected bool $isPaginate = true;

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Features::class, 'feature_id', 'id');
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bots::class, 'bot_id', 'id');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(FeatureHookRoutes::class, 'hook_id', 'id');
    }
}