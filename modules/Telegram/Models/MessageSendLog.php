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
 * @property int|null $sent_at  Unix 时间戳
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

    /**
     * 把 sent_at 也登记为日期列
     *
     * Laravel 12 的 getDates() 硬编码只返回 created_at/updated_at（$dates 属性已失效），
     * 自定义时间列必须覆写此方法登记。否则 fill()/setAttribute() 不会调用
     * fromDateTime()，Carbon 被 PDO 转成 'Y-m-d H:i:s' 字符串写入 unsigned int 列，
     * 触发 1265 Data truncated（群发回执因此反复重试，导致同一条消息连发 3 次）。
     * 登记后由基类 $dateFormat='U' 统一格式化为 Unix 整数。
     *
     * 注意：不能用 'datetime' / 'timestamp' cast——它们同样会输出字符串。
     */
    public function getDates(): array
    {
        return array_merge(parent::getDates(), ['sent_at']);
    }

    public array $searchable = [
        'send_id' => '=',
        'chat_id' => '=',
        'status' => '=',
    ];

    protected bool $isPaginate = true;
}
