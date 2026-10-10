<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 商户号路由：商户号 → (机器人, 群, 上游)
 *
 * 取代旧实现的 Cache::forever('mid', chat_id)：
 *   - 多商户多 bot：每个商户一行，互不覆盖
 *   - 可审计：谁绑的、什么时候绑的、走哪个上游
 *   - 唯一约束：一个商户号只能绑一个群，避免通知发串
 *
 * @property int $id
 * @property string $merchant_id
 * @property int $bot_id
 * @property string $chat_id
 * @property int|null $third_config_id
 * @property int|null $feature_id
 * @property bool $status
 */
class MerchantRoute extends Model
{
    protected $table = 'merchant_routes';

    protected $fillable = [
        'id', 'merchant_id', 'bot_id', 'chat_id', 'third_config_id',
        'feature_id', 'status', 'remark',
        // 不列进来 CatchAdmin 不会写时间戳
        'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    protected array $fields = [
        'id', 'merchant_id', 'bot_id', 'chat_id', 'third_config_id',
        'feature_id', 'status', 'remark', 'created_at', 'updated_at',
    ];

    protected array $form = [
        'merchant_id', 'bot_id', 'chat_id', 'third_config_id', 'feature_id', 'status', 'remark',
    ];

    public array $searchable = [
        'merchant_id' => 'like',
        'chat_id' => '=',
        'bot_id' => '=',
    ];

    protected bool $isPaginate = true;

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bots::class, 'bot_id');
    }

    public function thirdConfig(): BelongsTo
    {
        return $this->belongsTo(ThirdApiConfig::class, 'third_config_id');
    }
}
