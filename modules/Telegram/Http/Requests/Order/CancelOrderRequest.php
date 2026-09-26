<?php

namespace Modules\Telegram\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remark' => 'required|string|max:500',
        ];
    }

    public function attributes(): array
    {
        return [
            'remark' => '取消原因',
        ];
    }

    public function messages(): array
    {
        return [
            'remark.required' => '取消原因不能为空',
        ];
    }
}

