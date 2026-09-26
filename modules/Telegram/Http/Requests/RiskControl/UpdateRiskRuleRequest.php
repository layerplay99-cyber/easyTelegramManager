<?php

namespace Modules\Telegram\Http\Requests\RiskControl;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRiskRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:100',
            'conditions' => 'sometimes|array',
            'conditions.daily_amount' => 'nullable|numeric|min:0',
            'conditions.daily_count' => 'nullable|integer|min:0',
            'conditions.single_amount' => 'nullable|numeric|min:0',
            'conditions.ip_limit' => 'nullable|integer|min:0',
            'action' => 'sometimes|in:reject,manual_audit,freeze,notify',
            'action_config' => 'nullable|array',
            'risk_level' => 'sometimes|in:1,2,3,4',
            'priority' => 'nullable|integer|min:0|max:999',
            'remark' => 'nullable|string|max:500',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => '规则名称',
            'conditions' => '规则条件',
            'action' => '触发动作',
            'risk_level' => '风险等级',
            'priority' => '优先级',
        ];
    }
}

