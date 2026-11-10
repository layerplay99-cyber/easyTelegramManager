<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream\Adapters\Demo;

use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Upstream\Adapters\BaseUpstreamAdapter;
use Modules\Telegram\Services\Upstream\UpstreamRequest;
use Modules\Telegram\Services\Upstream\UpstreamResponse;
use Modules\Telegram\Services\Upstream\UpstreamResult;

/**
 * 【教学示例 2 / 4】只改「响应」：字段别名 + 业务成功码 + 解包
 *
 * 最常见的场景：请求参数各家都一样，只是返回结构不同。
 * 假设「示例甲」这个厂商的查询余额接口返回：
 *
 *   HTTP 200
 *   {
 *     "code": 0,
 *     "msg": "ok",
 *     "data": {
 *       "available_funds": "978479062.00",
 *       "currency": {"code": "VND", "symbol": "₫"}
 *     }
 *   }
 *
 * 而平台标准结构约定为：
 *
 *   {"data": {"balance": "978479062.00", "currency": "VND"}}
 *
 * 于是后台模板永远写 {{data.balance}} / {{data.currency}}，
 * 换任何上游都不用改模板，也不用在功能代码里写 ?? 兼容链。
 *
 * 这个例子只覆写 normalize()，传输层检查全部由基类兜住 —— 推荐写法。
 *
 * ⚠️ 注意：`available_funds` 是「示例甲」这一条上游自己的返回长相，不是通用协议，
 *    也不属于平台。接真实上游时，把本文件复制到 `Adapters/<上游名>/` 下改名，
 *    只写那一条上游的字段；不要直接选它，也不要往它里面加别家的字段别名。
 *
 * ⚠️ 教学示例，不要在生产环境的三方配置里选中它。
 */
class DemoFlatBalanceAdapter extends BaseUpstreamAdapter
{
    public static function key(): string
    {
        return 'demo.flat_balance';
    }

    public static function label(): string
    {
        return '示例 2 · 余额字段别名（示例甲）';
    }

    public static function description(): string
    {
        return '教学：只覆写 normalize()，处理业务码 code!=0、available_funds→balance、currency.code→currency。';
    }

    /**
     * 请求前：本厂商的参数名与平台标准一致，不用改，继承基类默认实现即可。
     * （想看怎么改请求参数，见示例 3 / 4）
     */

    /**
     * 响应后：厂商私有结构 → 平台标准结构
     */
    protected function normalize(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult {
        $raw = $response->payload();
        $code = $this->businessCode($response);

        // ① 业务成功码：code 不为 0 就是失败，哪怕 HTTP 是 200。
        //    不写这步，群里会出现「查询成功，余额：-」这种假结果。
        if (! $this->businessOk($response)) {
            return UpstreamResult::fail(
                $this->businessMessage($response),
                $raw,
                $code,
                $response->httpStatus
            );
        }

        // ② 解包：业务数据可能在 data / result.data / 顶层，基类 helper 自动找
        $wallet = $this->unwrap($response);

        // ③ 字段别名：从多个候选 key 里取第一个有值的。
        //    真实项目里通常只写本厂商的那个 key，这里多写几个是演示「容忍上游改字段名」。
        $balance = $this->firstOf($response, [
            'data.available_funds',
            'data.balance',
            'data.available_balance',
            'available_funds',
            'balance',
        ]);

        // ④ 嵌套字段拍平：厂商给对象，平台标准要字符串
        $currency = $wallet['currency'] ?? null;
        if (is_array($currency)) {
            $currency = $currency['code'] ?? $currency['symbol'] ?? null;
        }

        // ⑤ 产出标准结构。注意 data 是我们构造的，raw 是原始报文 ——
        //    排查问题时模板里用 {{_raw}} 还能看到厂商原文。
        $standard = [
            'data' => [
                'balance' => $balance,
                'currency' => $currency,
            ],
        ];

        return UpstreamResult::ok(
            data: $standard,
            raw: $raw,
            code: $code,
            httpStatus: $response->httpStatus
        );
    }
}
