<?php

namespace Modules\Telegram\Http\Requests\Wallet;



use Illuminate\Foundation\Http\FormRequest;

class AdjustBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|in:reward,deduct',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:500',
        ];
    }

    public function attributes(): array
    {
        return [
            'amount' => '金额',
            'type' => '类型',
            'title' => '标题',
            'description' => '描述',
        ];
    }

    /**
     * 获取格式化的金额
     */
    public function getFormattedAmount(): float
    {
        return (float) $this->input('amount');
    }

    /**
     * 是否为奖励
     */
    public function isReward(): bool
    {
        return $this->input('type') === 'reward';
    }
}
