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
        // 新增要字段齐全；修改允许只传要改的那个字段（局部更新）。
        //
        // 以前 PUT 也要求 username / api_token / url_token 必填，
        // 于是「只改三方上游」提交一个字段就被 422 拦下，
        // 只能新开一个 PUT bots/{id}/third-config 绕开 ——
        // 而新路由在开了 route:cache 的环境里必须重新 cache 才生效，
        // 没跑就直接「route_not_found_or_register」。
        // 这里放开局部更新，前端就可以走一直在的 apiResource 路由。
        $creating = $this->isMethod('post');
        $requiredOrSometimes = $creating ? 'required' : 'sometimes';

        return [
            // url_token 就是 Telegram 回调带回来的 secret_token，webhook 靠它认机器人。
            // 重复的话两个机器人的 update 会被认成同一个 —— 直接串，必须唯一。
            'url_token' => [
                $requiredOrSometimes,
                'string',
                Rule::unique('bots', 'url_token')
                    ->where(fn ($query) => $query->where('deleted_at', 0))
                    ->ignore($this->route('bot')),
            ],
            'username' => $requiredOrSometimes . '|string',
            'api_token' => $requiredOrSometimes . '|string',
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
        // 「自动补」只在该字段真的出现在这次请求里时才做：
        // 否则「只改三方上游」这种局部提交会把 url_token 重新生成一遍、
        // webhook_url 也顺手改回默认值 —— webhook 当场断掉。
        // 新增（POST）不受影响，没给也照旧自动生成。
        $shouldFill = fn (string $field) => $this->isMethod('post') || $this->has($field);

        if ($shouldFill('webhook_url') && empty($this->input('webhook_url'))) {
            $default = rtrim(config('app.url'), '/') . '/api/webhook/pull';
            $this->merge(['webhook_url' => $default]);
        }

        // 手填 url_token 很容易两个机器人填成一样的（复制机器人 / 留默认值），
        // 留空时自动生成随机值，从源头杜绝重复。
        if ($shouldFill('url_token') && empty($this->input('url_token'))) {
            $this->merge(['url_token' => Str::random(32)]);
        }
    }

}
