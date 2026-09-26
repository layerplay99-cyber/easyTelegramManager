<?php

namespace Modules\Telegram\Http\Requests\PaymentChannel;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:50|unique:payment_channels,code,' . $id,
            'type' => 'sometimes|in:recharge,withdraw,both',
            'currency' => 'sometimes|string|max:10',
            'method' => 'sometimes|string|max:50',
            'submit_api' => 'nullable|url|max:500',
            'query_api' => 'nullable|url|max:500',
            'merchant_id' => 'nullable|string|max:100',
            'secret_key' => 'nullable|string|max:255',
            'extra_config' => 'nullable|array',
            'fee_rate' => 'nullable|numeric|min:0|max:100',
            'fixed_fee' => 'nullable|numeric|min:0',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'daily_limit' => 'nullable|numeric|min:0',
            'daily_count_limit' => 'nullable|integer|min:0',
            'priority' => 'nullable|integer',
            'remark' => 'nullable|string|max:500',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => '通道名称',
            'code' => '通道编码',
            'type' => '通道类型',
            'fee_rate' => '手续费率',
            'min_amount' => '最小金额',
            'max_amount' => '最大金额',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->submit_api) {
            $this->merge(['submit_api' => rtrim($this->submit_api, '/')]);
        }
        if ($this->query_api) {
            $this->merge(['query_api' => rtrim($this->query_api, '/')]);
        }
    }
}

