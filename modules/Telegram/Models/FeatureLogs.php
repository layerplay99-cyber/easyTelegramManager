<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

/**
 * 功能执行日志
 *
 * 新建一张干净的日志表（区别于旧的 features_logs）：
 * 记录成功/失败、耗时、命令、请求ID，可用于排查与度量。
 *
 * @property int $id
 * @property int|null $feature_id
 * @property string|null $command
 * @property int|null $bot_id
 * @property int|null $chat_id
 * @property int|null $user_id
 * @property string|null $request_id
 * @property bool $success
 * @property string $level
 * @property string|null $message
 * @property array|null $meta
 * @property int $duration_ms
 */
class FeatureLogs extends Model
{
    protected $table = 'feature_logs';

    protected $fillable = ['id','feature_id','command','bot_id','chat_id','user_id','request_id','success','level','message','meta','duration_ms'];

    protected $casts = [
        'success' => 'boolean',
        'meta' => 'array',
    ];

    protected array $fields = ['id','feature_id','command','bot_id','chat_id','user_id','success','level','message','duration_ms','created_at'];

    protected array $form = [];

    public array $searchable = ['feature_id' => '=','command' => 'like','success' => '='];

    protected bool $isPaginate = true;
}