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
        // webhook_url 留空（含 null / 空串）时，默认用当前平台回调地址（与 api/webhook/pull 路由一致）。
        // 注意：前端在「当前平台」模式下新建时字段初始为 null（不是空串），必须同时兼容 null 与 ''，
        // 否则 prepareForValidation 不触发默认填充，落库为空，setWebhook 时 Telegram 报 Invalid URL Provided。
        if (empty($this->input('webhook_url'))) {
            $default = rtrim(config('app.url'), '/') . '/api/webhook/pull';
            $this->merge(['webhook_url' => $default]);
        }
    }
}
