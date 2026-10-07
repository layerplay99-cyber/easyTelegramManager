<?php

namespace Modules\Telegram\Http\Requests;

use Illuminate\Foundation\Http\FormRequest as Request;

class BotsRequest extends Request
{
    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            'url_token' => 'required|string',
            'username' => 'required|string',
            'api_token' => 'required|string',
            'webhook_url' => 'nullable|url',
            'description' => 'nullable|string',
            'enabled' => 'nullable|boolean',
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

    protected function prepareForValidation()
    {
        // webhook_url 留空时，默认用当前平台回调地址（与 api/webhook/pull 路由一致）
        if ($this->missing('webhook_url') || $this->input('webhook_url') === '') {
            $default = rtrim(config('app.url'), '/') . '/api/webhook/pull';
            $this->merge(['webhook_url' => $default]);
        }
    }
}
