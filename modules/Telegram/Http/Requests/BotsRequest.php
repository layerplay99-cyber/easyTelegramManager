<?php

namespace Modules\Telegram\Http\Requests;

use Illuminate\Foundation\Http\FormRequest as Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BotsRequest extends Request
{
    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            // url_token 就是 Telegram 回调带回来的 secret_token，webhook 靠它认机器人。
            // 重复的话两个机器人的 update 会被认成同一个 —— 直接串，必须唯一。
            'url_token' => [
                'required',
                'string',
                Rule::unique('bots', 'url_token')
                    ->where(fn ($query) => $query->where('deleted_at', 0))
                    ->ignore($this->route('bot')),
            ],
            'username' => 'required|string',
            'api_token' => 'required|string',
            'webhook_url' => 'nullable|url',
            'description' => 'nullable|string',
            'enabled' => 'nullable|boolean',
            // 该机器人使用哪个三方上游（多用户各自不同上游的关键字段）
            'third_config_id' => 'nullable|integer|exists:thirdapi_config,id',
        ];
    }


    /**
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'url_token.unique' => '该 url_token 已被其它机器人使用，留空会自动生成一个',
        ];
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

        // 手填 url_token 很容易两个机器人填成一样的（复制机器人 / 留默认值），
        // 留空时自动生成随机值，从源头杜绝重复。
        if (empty($this->input('url_token'))) {
            $this->merge(['url_token' => Str::random(32)]);
        }
    }

}
