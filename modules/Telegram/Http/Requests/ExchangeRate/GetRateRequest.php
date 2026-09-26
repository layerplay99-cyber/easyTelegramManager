<?php

namespace Modules\Telegram\Http\Requests\ExchangeRate;

use Illuminate\Foundation\Http\FormRequest;

class GetRateRequest extends FormRequest
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
            'type' => 'nullable|in:buy,sell',
        ];
    }

    public function attributes(): array
    {
        return [
            'from_currency' => '源币种',
            'to_currency' => '目标币种',
            'type' => '汇率类型',
        ];
    }

    public function messages(): array
    {
        return [
            'to_currency.different' => '目标币种不能与源币种相同',
        ];
    }
}

