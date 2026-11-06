<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 机器人可绑定的上游（多对多候选池）
 *
 * 不用 CatchModel + DataRange：这张表没有 creator_id，
 * 越权判断统一走「上游配置自身的 DataScope 可见性」。
 */
class BotThirdConfig extends Model
{
    protected $table = 'bot_third_configs';

    protected $fillable = [
        'bot_id', 'third_config_id', 'is_default', 'tenant_id',
    ];

    protected $casts = [
        'is_default' => 'bool',
        'tenant_id' => 'int',
    ];
}
