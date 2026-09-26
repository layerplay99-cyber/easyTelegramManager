<?php

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

class RiskControlRule extends Model
{
    protected $table = 'risk_control_rules';

    protected $fillable = [
        'name', 'code', 'type', 'conditions', 'action', 'action_config',
        'risk_level', 'priority', 'status', 'remark'
    ];

    /**
     * 风险等级常量
     */
    const RISK_LEVEL_LOW = 1;      // 低
    const RISK_LEVEL_MEDIUM = 2;   // 中
    const RISK_LEVEL_HIGH = 3;     // 高
    const RISK_LEVEL_CRITICAL = 4; // 严重

    /**
     * 获取条件（JSON转数组）
     */
    public function getConditionsAttribute($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    /**
     * 设置条件（数组转JSON）
     */
    public function setConditionsAttribute($value)
    {
        $this->attributes['conditions'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * 获取动作配置（JSON转数组）
     */
    public function getActionConfigAttribute($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    /**
     * 设置动作配置（数组转JSON）
     */
    public function setActionConfigAttribute($value)
    {
        $this->attributes['action_config'] = is_array($value) ? json_encode($value) : $value;
    }
}

