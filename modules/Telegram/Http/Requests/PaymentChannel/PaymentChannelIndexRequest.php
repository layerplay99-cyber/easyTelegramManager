<?php

namespace Modules\Telegram\Http\Requests\PaymentChannel;

use Illuminate\Foundation\Http\FormRequest;

class PaymentChannelIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'nullable|in:recharge,withdraw,both',
            'currency' => 'nullable|string|max:10',
            'method' => 'nullable|string|max:50',
            'status' => 'nullable|in:0,1,2',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function attributes(): array
    {
        return [
            'type' => '通道类型',
            'currency' => '币种',
            'method' => '支付方式',
            'status' => '状态',
        ];
    }
}

