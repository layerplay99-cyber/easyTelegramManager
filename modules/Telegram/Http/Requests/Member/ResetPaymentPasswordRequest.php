<?php

namespace Modules\Telegram\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class ResetPaymentPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => 'required|string|min:6|max:20|regex:/^[0-9]+$/',
        ];
    }

    public function attributes(): array
    {
        return [
            'password' => '支付密码',
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => '支付密码不能为空',
            'password.min' => '支付密码至少6位',
            'password.max' => '支付密码最多20位',
            'password.regex' => '支付密码只能是数字',
        ];
    }
}

