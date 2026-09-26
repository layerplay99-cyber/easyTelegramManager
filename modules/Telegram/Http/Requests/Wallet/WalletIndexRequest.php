<?php

namespace Modules\Telegram\Http\Requests\Wallet;


use Illuminate\Foundation\Http\FormRequest;

class WalletIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => 'nullable|integer|exists:members,id',
            'currency' => 'nullable|string|max:10',
            'status' => 'nullable|in:0,1',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function attributes(): array
    {
        return [
            'member_id' => '会员ID',
            'currency' => '币种',
            'status' => '状态',
            'limit' => '每页数量',
        ];
    }
}
