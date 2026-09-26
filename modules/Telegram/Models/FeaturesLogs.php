<?php
declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bot_id
 * @property string $chat_id
 * @property int $features_id
 * @property string $level
 * @property string $message
 * @property string|null $meta
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read Features $feature
 * @property-read Bots $bot
 */
class FeaturesLogs extends Model
{
    protected $table = 'features_logs';

    protected $fillable = [
        'id',
        'bot_id',
        'chat_id',
        'features_id',
        'level',
        'message',
        'meta',
        'created_at',
        'updated_at',
    ];

    protected array $fields = [
        'id',
        'bot_id',
        'chat_id',
        'features_id',
        'level',
        'message',
        'created_at',
    ];

    protected array $form = [
        'bot_id',
        'chat_id',
        'features_id',
        'level',
        'message',
        'meta',
    ];

    public array $searchable = [
        'bot_id' => '=',
        'chat_id' => '=',
        'features_id' => '=',
        'level' => '=',
    ];

    protected bool $isPaginate = true;

    // 关联关系
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Features::class, 'features_id', 'id');
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bots::class, 'bot_id', 'id');
    }
}
