<?php

declare(strict_types=1);

namespace App\Listeners;

use Catch\Enums\Code;
use Catch\Listeners\RequestHandledListener;

/**
 * 后台接口统一响应结构：永远只有一层 {code, message, data}
 *
 * 【背景】
 * 框架的 RequestHandledListener 只判断「来自后台（Request-from: Dashboard）」，
 * 就把控制器返回的任何 JsonResponse 再包一层 {code, message, data}。
 * 于是同一套接口出现两种结构：
 *   · 控制器直接返回数据（数组/模型/分页）→ 一层：{code, message, data: 数据}
 *   · 控制器自己返回了 {code, message, data} → 两层：{code, message, data: {code, message, data: 数据}}
 * 前端只能靠「剥一层还是剥两层」猜，猜错就是下拉为空这种静默故障。
 *
 * 【做法】
 * 控制器已经返回标准信封时直接采用它，不再包第二层。
 * 这样无论控制器怎么写，后台拿到的永远是单层结构；
 * 非后台请求（外部回调 /api/tg/*）本来就不走包装，行为不变。
 *
 * 注册方式见 AppServiceProvider::register()（覆盖 catch.response.request_handled_listener）。
 */
class DashboardResponseListener extends RequestHandledListener
{
    /**
     * 已经是标准信封就直接返回，避免被再包一层
     *
     * @param mixed $data
     * @return array
     */
    protected function formatData(mixed $data): array
    {
        $payload = $this->toArray($data);

        if ($this->isEnvelope($payload)) {
            // ⚠️ code / message 必须沿用里层的：控制器可能用 HTTP 200 + code=10005
            //    表示业务失败（前端靠 code 判断），统一覆盖成「成功」会把失败吞掉。
            //    只有里层没给 message 时才用框架的文案。
            //    分页信息（total/limit/page）也要带上，不能因为不再包一层就丢掉。
            $code = is_numeric($payload['code'] ?? null)
                ? (int) $payload['code']
                : Code::SUCCESS->value();

            $message = (string) ($payload['message'] ?? '') !== ''
                ? (string) $payload['message']
                : Code::SUCCESS->message();

            return array_merge(
                ['code' => $code, 'message' => $message],
                array_intersect_key($payload, array_flip(['data', 'total', 'limit', 'page']))
            );
        }

        return parent::formatData($data);
    }

    /**
     * 是否已经是 {code, message, data} 形式的响应体
     */
    protected function isEnvelope(array $data): bool
    {
        return array_key_exists('code', $data)
            && array_key_exists('message', $data)
            && array_key_exists('data', $data);
    }

    /**
     * 统一转成数组
     *
     * $response->getData() 走的是 Illuminate\Http\JsonResponse::getData()，
     * 默认 $assoc = false —— 拿到的是 stdClass 而不是数组，直接 is_array() 判断会漏。
     */
    protected function toArray(mixed $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        if ($data instanceof \JsonSerializable || is_object($data)) {
            return json_decode(json_encode($data), true) ?: [];
        }

        return [];
    }
}
