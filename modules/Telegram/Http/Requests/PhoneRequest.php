<?php

namespace Modules\Telegram\Http\Requests;

use Illuminate\Foundation\Http\FormRequest as Request;

class PhoneRequest extends Request
{
    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            'phone' => 'required',

        ];
    }


    /**
     *
     * @return array
     */
    public function messages(): array
    {
        return [];
    }
}
