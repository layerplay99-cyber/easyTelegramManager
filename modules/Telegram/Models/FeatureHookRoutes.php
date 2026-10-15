<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 回调路由：路由键值（商户ID）→ 机器人 + 群
 *
 * 上游回调报文里带商户ID，单机器人时代「商户ID → chat_id」就够，
 * 多机器人时必须同时定下「用哪个机器人发」，否则会串到别的机器人 / 别的群。
 *
 * @property int $id
 * @property int $hook_id
 * @property string $route_value
 * @property int $bot_id
 * @property string $chat_id
 * @property string|null $remark
 * @property bool $enabled
 */
class FeatureHookRoutes extends Model
{
    protected $table = 'feature_hook_routes';

    protected $fillable = [
        'id', 'hook_id', 'route_value', 'bot_id', 'chat_id', 'remark', 'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    protected array $fields = [
        'id', 'hook_id', 'route_value', 'bot_id', 'chat_id', 'remark', 'enabled', 'created_at',
    ];

    protected array $form = [
        'hook_id', 'route_value', 'bot_id', 'chat_id', 'remark', 'enabled',
    ];

    public array $searchable = [
        'hook_id' => '=',
        'route_value' => 'like',
        'bot_id' => '=',
    ];

    protected bool $isPaginate = false;

    public function hook(): BelongsTo
    {
        return $this->belongsTo(FeatureHooks::class, 'hook_id', 'id');
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bots::class, 'bot_id', 'id');
    }
}
