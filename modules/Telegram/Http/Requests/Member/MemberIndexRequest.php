<?php

namespace Modules\Telegram\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class MemberIndexRequest extends FormRequest
{
    /**
     * 授权验证
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 验证规则
     */
    public function rules(): array
    {
        return [
            'telegram_user_id' => 'nullable|integer',
            'telegram_username' => 'nullable|string|max:100',
            'status' => 'nullable|in:0,1,2',
            'register_ip' => 'nullable|ip',
            'created_at' => 'nullable|array|size:2',
            'created_at.*' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ];
    }

    /**
     * 字段名称
     */
    public function attributes(): array
    {
        return [
            'telegram_user_id' => 'Telegram用户ID',
            'telegram_username' => 'Telegram用户名',
            'status' => '状态',
            'register_ip' => '注册IP',
            'created_at' => '创建时间',
            'limit' => '每页数量',
        ];
    }

    /**
     * 获取处理后的查询参数
     */
    public function getSearchParams(): array
    {
        return array_filter([
            'telegram_user_id' => $this->input('telegram_user_id'),
            'telegram_username' => $this->input('telegram_username'),
            'status' => $this->input('status'),
            'register_ip' => $this->input('register_ip'),
            'created_at' => $this->input('created_at'),
        ], function ($value) {
            return !is_null($value);
        });
    }
}

