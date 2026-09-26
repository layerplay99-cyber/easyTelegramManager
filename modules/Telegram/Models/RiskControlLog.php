<?php

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;

class RiskControlLog extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'risk_control_logs';

    public $timestamps = false;

    protected $fillable = [
        'member_id', 'rule_id', 'order_no', 'type', 'risk_level',
        'trigger_data', 'action', 'action_result', 'status',
        'handler_id', 'handled_at', 'handle_remark', 'ip'
    ];

    /**
     * 状态常量
     */
    const STATUS_PENDING = 0;   // 待处理
    const STATUS_HANDLED = 1;   // 已处理
    const STATUS_IGNORED = 2;   // 已忽略

    /**
     * 关联会员
     */
    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /**
     * 关联规则
     */
    public function rule()
    {
        return $this->belongsTo(RiskControlRule::class, 'rule_id');
    }

    /**
     * 获取触发数据（JSON转数组）
     */
    public function getTriggerDataAttribute($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    /**
     * 设置触发数据（数组转JSON）
     */
    public function setTriggerDataAttribute($value)
    {
        $this->attributes['trigger_data'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * 获取动作结果（JSON转数组）
     */
    public function getActionResultAttribute($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    /**
     * 设置动作结果（数组转JSON）
     */
    public function setActionResultAttribute($value)
    {
        $this->attributes['action_result'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * 关联处理人（如果Admin模型存在）
     */
    public function handler()
    {
        // 检查Admin类是否存在
        if (class_exists('\Modules\Permissions\Models\Admin')) {
            return $this->belongsTo(\Modules\Permissions\Models\Admin::class, 'handler_id');
        }
        return null;
    }

    /**
     * 标记为已处理
     */
    public function markAsHandled(int $handlerId, string $remark = ''): void
    {
        $this->status = self::STATUS_HANDLED;
        $this->handler_id = $handlerId;
        $this->handled_at = now();
        $this->handle_remark = $remark;
        $this->save();
    }

    /**
     * 标记为已忽略
     */
    public function markAsIgnored(int $handlerId, string $remark = ''): void
    {
        $this->status = self::STATUS_IGNORED;
        $this->handler_id = $handlerId;
        $this->handled_at = now();
        $this->handle_remark = $remark;
        $this->save();
    }
}
