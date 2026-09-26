<?php

namespace Modules\Telegram\Http\Requests\ExchangeRate;

use Illuminate\Foundation\Http\FormRequest;

class CreateExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_currency' => 'required|string|max:10',
            'to_currency' => 'required|string|max:10|different:from_currency',
            'rate' => 'required|numeric|min:0|gt:0',
            'buy_rate' => 'nullable|numeric|min:0|gt:0',
            'sell_rate' => 'nullable|numeric|min:0|gt:0',
            'auto_update' => 'nullable|boolean',
            'source' => 'nullable|string|max:50',
        ];
    }

    public function attributes(): array
    {
        return [
            'from_currency' => '源币种',
            'to_currency' => '目标币种',
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
            'to_currency.different' => '目标币种不能与源币种相同',
            'rate.gt' => '汇率必须大于0',
            'buy_rate.gt' => '买入汇率必须大于0',
            'sell_rate.gt' => '卖出汇率必须大于0',
        ];
    }

    /**
     * 格式化汇率（保留8位小数）
     */
    public function getFormattedRates(): array
    {
        return [
            'rate' => round((float) $this->input('rate'), 8),
            'buy_rate' => $this->buy_rate ? round((float) $this->buy_rate, 8) : null,
            'sell_rate' => $this->sell_rate ? round((float) $this->sell_rate, 8) : null,
        ];
    }
}

