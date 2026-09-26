<?php

namespace Modules\Telegram\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class RechargeOrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_no' => 'nullable|string|max:50',
            'member_id' => 'nullable|integer|exists:members,id',
            'channel_id' => 'nullable|integer|exists:payment_channels,id',
            'status' => 'nullable|in:0,1,2,3,4,5',
            'currency' => 'nullable|string|max:10',
            'third_order_no' => 'nullable|string|max:100',
            'created_at' => 'nullable|array|size:2',
            'created_at.*' => 'nullable|date',
            'amount_min' => 'nullable|numeric|min:0',
            'amount_max' => 'nullable|numeric|min:0',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function attributes(): array
    {
        return [
            'order_no' => '订单号',
            'member_id' => '会员ID',
            'channel_id' => '通道ID',
            'status' => '状态',
            'currency' => '币种',
            'third_order_no' => '第三方订单号',
            'amount_min' => '最小金额',
            'amount_max' => '最大金额',
        ];
    }

    /**
     * 获取状态文本
     */
    public function getStatusText(): ?string
    {
        if (!$this->has('status')) {
            return null;
        }

        $statusMap = [
            '0' => '待支付',
            '1' => '已支付',
            '2' => '已完成',
            '3' => '已取消',
            '4' => '已超时',
            '5' => '失败',
        ];

        return $statusMap[$this->input('status')] ?? null;
    }
}

