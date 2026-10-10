<?php

namespace Modules\Telegram\Http\Requests\RiskControl;

use Illuminate\Foundation\Http\FormRequest;

class CreateRiskRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50|unique:risk_control_rules,code|alpha_dash',
            'type' => 'required|in:recharge,withdraw,transfer',
            'conditions' => 'required|array',
            'conditions.daily_amount' => 'nullable|numeric|min:0',
            'conditions.daily_count' => 'nullable|integer|min:0',
            'conditions.single_amount' => 'nullable|numeric|min:0',
            'conditions.ip_limit' => 'nullable|integer|min:0',
            'action' => 'required|in:reject,manual_audit,freeze,notify',
            'action_config' => 'nullable|array',
            'risk_level' => 'required|in:1,2,3,4',
            'priority' => 'nullable|integer|min:0|max:999',
            'status' => 'nullable|in:0,1',
            'remark' => 'nullable|string|max:500',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => '规则名称',
            'code' => '规则编码',
            'type' => '规则类型',
            'conditions' => '规则条件',
            'action' => '触发动作',
            'action_config' => '动作配置',
            'risk_level' => '风险等级',
            'priority' => '优先级',
        ];
    }

    public function messages(): array
    {
        return [
            'code.alpha_dash' => '规则编码只能包含字母、数字、下划线和破折号',
            'code.unique' => '规则编码已存在',
            'risk_level.in' => '风险等级必须是：1(低)、2(中)、3(高)、4(严重)',
            'action.in' => '触发动作必须是：reject(拒绝)、manual_audit(人工审核)、freeze(冻结)、notify(通知)',
        ];
    }

    /**
     * 获取风险等级文本
     */
    public function getRiskLevelText(): string
    {
        $levelMap = [
            '1' => '低',
            '2' => '中',
            '3' => '高',
            '4' => '严重',
        ];
        return $levelMap[$this->input('risk_level')] ?? '未知';
    }

    /**
     * 获取动作文本
     */
    public function getActionText(): string
    {
        $actionMap = [
            'reject' => '拒绝',
            'manual_audit' => '人工审核',
            'freeze' => '冻结账户',
            'notify' => '通知',
        ];
        return $actionMap[$this->input('action')] ?? '未知';
    }
}

