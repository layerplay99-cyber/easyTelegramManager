<?php

namespace Modules\Telegram\Http\Requests\Common;

use Catch\Base\CatchRequest as FormRequest;

class ToggleStatusRequest extends FormRequest
{
    /**
     * 验证规则
     */
    public function rules(): array
    {
        return [
            'status' => 'required|in:0,1',
        ];
    }

    /**
     * 字段名称
     */
    public function attributes(): array
    {
        return [
            'status' => '状态',
        ];
    }

    /**
     * 获取状态文本
     */
    public function getStatusText(): string
    {
        return $this->input('status') == 1 ? '已启用' : '已禁用';
    }
}
