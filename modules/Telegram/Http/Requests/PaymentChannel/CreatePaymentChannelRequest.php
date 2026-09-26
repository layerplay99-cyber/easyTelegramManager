<?php

namespace Modules\Telegram\Http\Requests\PaymentChannel;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50|unique:payment_channels,code',
            'type' => 'required|in:recharge,withdraw,both',
            'currency' => 'required|string|max:10',
            'method' => 'required|string|max:50',
            'submit_api' => 'nullable|url|max:500',
            'query_api' => 'nullable|url|max:500',
            'merchant_id' => 'nullable|string|max:100',
            'secret_key' => 'nullable|string|max:255',
            'extra_config' => 'nullable|array',
            'fee_rate' => 'nullable|numeric|min:0|max:100',
            'fixed_fee' => 'nullable|numeric|min:0',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0|gte:min_amount',
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
            'currency' => '币种',
            'method' => '支付方式',
            'submit_api' => '提单API',
            'query_api' => '查询API',
            'merchant_id' => '商户号',
            'secret_key' => '密钥',
            'fee_rate' => '手续费率',
            'fixed_fee' => '固定手续费',
            'min_amount' => '最小金额',
            'max_amount' => '最大金额',
            'daily_limit' => '每日限额',
            'daily_count_limit' => '每日次数限制',
            'priority' => '优先级',
        ];
    }

    public function messages(): array
    {
        return [
            'max_amount.gte' => '最大金额必须大于或等于最小金额',
            'submit_api.url' => '提单API必须是有效的URL',
            'query_api.url' => '查询API必须是有效的URL',
        ];
    }

    /**
     * 处理后的数据
     */
    protected function prepareForValidation(): void
    {
        // 清理URL末尾的斜杠
        if ($this->submit_api) {
            $this->merge(['submit_api' => rtrim($this->submit_api, '/')]);
        }
        if ($this->query_api) {
            $this->merge(['query_api' => rtrim($this->query_api, '/')]);
        }
    }
}

