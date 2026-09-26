<?php

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;

class Notification extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'notifications';

    public $timestamps = false;

    protected $fillable = [
        'member_id', 'type', 'title', 'content', 'related_type',
        'related_id', 'channel', 'send_status', 'sent_at', 'error_msg',
        'read_status', 'read_at'
    ];

    /**
     * 发送状态常量
     */
    const SEND_STATUS_PENDING = 0;  // 待发送
    const SEND_STATUS_SENT = 1;     // 已发送
    const SEND_STATUS_FAILED = 2;   // 发送失败

    /**
     * 阅读状态常量
     */
    const READ_STATUS_UNREAD = 0;   // 未读
    const READ_STATUS_READ = 1;     // 已读

    /**
     * 关联会员
     */
    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /**
     * 标记为已读
     */
    public function markAsRead(): void
    {
        $this->read_status = self::READ_STATUS_READ;
        $this->read_at = now();
        $this->save();
    }
}

