<?php

namespace Modules\Telegram\Http\Requests\ExchangeRate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rate' => 'sometimes|numeric|min:0|gt:0',
            'buy_rate' => 'nullable|numeric|min:0|gt:0',
            'sell_rate' => 'nullable|numeric|min:0|gt:0',
            'auto_update' => 'nullable|boolean',
            'source' => 'nullable|string|max:50',
        ];
    }

    public function attributes(): array
    {
        return [
            'rate' => '汇率',
            'buy_rate' => '买入汇率',
            'sell_rate' => '卖出汇率',
            'auto_update' => '自动更新',
            'source' => '汇率来源',
        ];
    }

    public function messages(): array
    {
        return [
            'rate.gt' => '汇率必须大于0',
            'buy_rate.gt' => '买入汇率必须大于0',
            'sell_rate.gt' => '卖出汇率必须大于0',
        ];
    }
}

