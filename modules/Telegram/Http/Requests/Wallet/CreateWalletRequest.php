<?php

namespace Modules\Telegram\Http\Requests\Wallet;


use Illuminate\Foundation\Http\FormRequest;

class CreateWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => 'required|integer|exists:members,id',
            'currency' => 'required|string|max:10',
        ];
    }

    public function attributes(): array
    {
        return [
            'member_id' => '会员ID',
            'currency' => '币种',
        ];
    }
}
