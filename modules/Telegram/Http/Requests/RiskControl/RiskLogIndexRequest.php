<?php

namespace Modules\Telegram\Http\Requests\RiskControl;

use Illuminate\Foundation\Http\FormRequest;

class RiskLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => 'nullable|integer|exists:members,id',
            'rule_id' => 'nullable|integer|exists:risk_control_rules,id',
            'type' => 'nullable|in:recharge,withdraw,transfer',
            'risk_level' => 'nullable|in:1,2,3,4',
            'status' => 'nullable|in:0,1,2',
            'created_at' => 'nullable|array|size:2',
            'created_at.*' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function attributes(): array
    {
        return [
            'member_id' => '会员ID',
            'rule_id' => '规则ID',
            'type' => '类型',
            'risk_level' => '风险等级',
            'status' => '状态',
        ];
    }
}

