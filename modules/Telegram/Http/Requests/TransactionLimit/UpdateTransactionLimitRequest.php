<?php

namespace Modules\Telegram\Http\Requests\TransactionLimit;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:100',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'daily_amount' => 'nullable|numeric|min:0',
            'daily_count' => 'nullable|integer|min:0',
            'monthly_amount' => 'nullable|numeric|min:0',
            'monthly_count' => 'nullable|integer|min:0',
            'status' => 'nullable|in:0,1',
            'remark' => 'nullable|string|max:500',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => '限额名称',
            'min_amount' => '最小金额',
            'max_amount' => '最大金额',
            'daily_amount' => '每日限额',
            'daily_count' => '每日次数',
            'monthly_amount' => '每月限额',
            'monthly_count' => '每月次数',
        ];
    }

    /**
     * 验证最大金额不能小于最小金额
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->has('min_amount') && $this->has('max_amount')) {
                if ($this->max_amount > 0 && $this->max_amount < $this->min_amount) {
                    $validator->errors()->add('max_amount', '最大金额必须大于或等于最小金额');
                }
            }
        });
    }
}
