<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

/**
 * 群发回执
 *
 * 没有 creator_id（回执跟随任务，任务本身受数据权限约束），故不接 DataRange。
 *
 * @property int $id
 * @property int $send_id
 * @property string $chat_id
 * @property string $status
 * @property string|null $error
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class MessageSendLog extends Model
{
    protected $table = 'message_send_logs';

    protected $fillable = [
        'id',
        'send_id',
        'chat_id',
        'status',
        'error',
        'sent_at',
        'created_at',
        'updated_at'
    ];

    protected array $fields = [
        'id',
        'send_id',
        'chat_id',
        'status',
        'error',
        'sent_at',
        'created_at'
    ];

    protected array $form = [
        'send_id',
        'chat_id',
        'status',
        'error',
        'sent_at'
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public array $searchable = [
        'send_id' => '=',
        'chat_id' => '=',
        'status' => '=',
    ];

    protected bool $isPaginate = true;
}
