<?php

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

class OperationLog extends Model
{
    protected $table = 'operation_logs';

    public $timestamps = false;

    protected $fillable = [
        'member_id', 'admin_id', 'module', 'action', 'method',
        'url', 'params', 'response', 'related_type', 'related_id',
        'ip', 'user_agent', 'execute_time', 'status', 'error_msg'
    ];

    /**
     * 获取参数（JSON转数组）
     */
    public function getParamsAttribute($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    /**
     * 设置参数（数组转JSON）
     */
    public function setParamsAttribute($value)
    {
        $this->attributes['params'] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
    }

    /**
     * 获取响应（JSON转数组）
     */
    public function getResponseAttribute($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    /**
     * 设置响应（数组转JSON）
     */
    public function setResponseAttribute($value)
    {
        $this->attributes['response'] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
    }

    /**
     * 关联会员
     */
    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /**
     * 关联管理员（如果Admin模型存在）
     */
    public function admin()
    {
        // 检查Admin类是否存在
        if (class_exists('\Modules\Permissions\Models\Admin')) {
            return $this->belongsTo(\Modules\Permissions\Models\Admin::class, 'admin_id');
        }
        return null;
    }

    /**
     * 记录操作日志
     */
    public static function record(array $data): void
    {
        self::create([
            'member_id' => $data['member_id'] ?? null,
            'admin_id' => $data['admin_id'] ?? null,
            'module' => $data['module'],
            'action' => $data['action'],
            'method' => request()->method(),
            'url' => request()->url(),
            'params' => $data['params'] ?? request()->all(),
            'response' => $data['response'] ?? null,
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'execute_time' => $data['execute_time'] ?? 0,
            'status' => $data['status'] ?? 1,
            'error_msg' => $data['error_msg'] ?? null,
        ]);
    }
}
