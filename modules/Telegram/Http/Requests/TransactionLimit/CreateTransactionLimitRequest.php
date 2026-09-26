<?php

namespace Modules\Telegram\Http\Requests\TransactionLimit;

use Illuminate\Foundation\Http\FormRequest;

class CreateTransactionLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'type' => 'required|in:recharge,withdraw',
            'level' => 'required|string|max:30|alpha_dash',
            'currency' => 'required|string|max:10',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0|gte:min_amount',
            'daily_amount' => 'nullable|numeric|min:0',
            'daily_count' => 'nullable|integer|min:0',
            'monthly_amount' => 'nullable|numeric|min:0',
            'monthly_count' => 'nullable|integer|min:0',
            'remark' => 'nullable|string|max:500',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => '限额名称',
            'type' => '类型',
            'level' => '等级',
            'currency' => '币种',
            'min_amount' => '最小金额',
            'max_amount' => '最大金额',
            'daily_amount' => '每日限额',
            'daily_count' => '每日次数',
            'monthly_amount' => '每月限额',
            'monthly_count' => '每月次数',
        ];
    }

    public function messages(): array
    {
        return [
            'max_amount.gte' => '最大金额必须大于或等于最小金额',
            'level.alpha_dash' => '等级只能包含字母、数字、下划线和破折号',
            'type.in' => '类型必须是：recharge(充值)或withdraw(提现)',
        ];
    }

    /**
     * 验证限额配置是否已存在
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isEmpty()) {
                $exists = \Modules\Telegram\Models\TransactionLimit::where('type', $this->type)
                    ->where('level', $this->level)
                    ->where('currency', $this->currency)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('type', '该限额配置已存在');
                }
            }
        });
    }
}

