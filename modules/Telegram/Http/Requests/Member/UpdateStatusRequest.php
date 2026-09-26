<?php

namespace Modules\Telegram\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:0,1,2',
            'remark' => 'nullable|string|max:500',
        ];
    }

    public function attributes(): array
    {
        return [
            'status' => '状态',
            'remark' => '备注',
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => '状态不能为空',
            'status.in' => '状态值无效，必须为0(禁用)、1(正常)或2(冻结)',
        ];
    }

    /**
     * 获取状态文本
     */
    public function getStatusText(): string
    {
        $statusMap = [
            '0' => '禁用',
            '1' => '正常',
            '2' => '冻结',
        ];
        return $statusMap[$this->input('status')] ?? '未知';
    }
}

