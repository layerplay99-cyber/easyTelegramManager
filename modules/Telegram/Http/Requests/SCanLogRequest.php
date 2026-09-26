<?php

namespace Modules\Telegram\Http\Requests;

use Illuminate\Foundation\Http\FormRequest as Request;

class SCanLogRequest extends Request
{
    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            'tuser_id' => 'required|integer',
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
