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
        // 设置 webhook_url 默认值为 APP_URL.'/api/bot/cb/webhook'
        if ($this->missing('webhook_url') || $this->input('webhook_url') === '') {
            $default = rtrim(config('app.url'), '/') . '/api/webhook/pull';
            $this->merge(['webhook_url' => $default]);
        }
    }
}
