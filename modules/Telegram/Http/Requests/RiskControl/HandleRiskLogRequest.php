<?php

namespace Modules\Telegram\Http\Requests\RiskControl;

use Illuminate\Foundation\Http\FormRequest;

class HandleRiskLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:handled,ignored',
            'remark' => 'nullable|string|max:500',
        ];
    }

    public function attributes(): array
    {
        return [
            'action' => '处理动作',
            'remark' => '处理备注',
        ];
    }

    public function messages(): array
    {
        return [
            'action.in' => '处理动作必须是：handled(已处理)或ignored(已忽略)',
        ];
    }

    /**
     * 是否标记为已处理
     */
    public function isHandled(): bool
    {
        return $this->input('action') === 'handled';
    }
}

