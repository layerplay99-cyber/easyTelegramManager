<?php

namespace Modules\Telegram\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class BatchProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:withdraw_orders,id',
            'action' => 'required|in:complete,cancel,processing',
            'remark' => 'nullable|string|max:500',
        ];
    }

    public function attributes(): array
    {
        return [
            'ids' => '订单ID列表',
            'action' => '操作类型',
            'remark' => '备注',
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => '请选择要处理的订单',
            'ids.min' => '至少选择一个订单',
            'action.required' => '操作类型不能为空',
            'action.in' => '操作类型只能是：complete(完成)、cancel(取消)、processing(处理中)',
        ];
    }

    /**
     * 获取操作文本
     */
    public function getActionText(): string
    {
        $actionMap = [
            'complete' => '完成',
            'cancel' => '取消',
            'processing' => '处理中',
        ];

        return $actionMap[$this->input('action')] ?? '未知';
    }
}

