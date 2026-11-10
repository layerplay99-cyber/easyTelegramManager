<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream\Adapters\Demo;

use Modules\Telegram\Contracts\UpstreamAdapter;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Upstream\UpstreamRequest;
use Modules\Telegram\Services\Upstream\UpstreamResponse;
use Modules\Telegram\Services\Upstream\UpstreamResult;

/**
 * 【教学示例 1 / 4】最小插件：从零实现接口，不继承任何基类
 *
 * 这个例子故意不用 BaseUpstreamAdapter，让你看清一个插件最少要写什么：
 *   - 3 个静态方法：key / label / description（后台下拉靠它们）
 *   - 2 个实例方法：prepareRequest / parseResponse（两个钩子）
 *
 * 它做的是「原样透传」——等价于 DefaultAdapter，但完整展示了
 * 传输层错误、业务成功码、原始报文这些判断该怎么写。
 *
 * 实际项目里绝大多数插件继承 BaseUpstreamAdapter 更省事
 * （见 DemoFlatBalanceAdapter），这里只是把底层摊开给你看。
 *
 * ⚠️ 教学示例，不要在生产环境的三方配置里选中它。
 */
class DemoMinimalAdapter implements UpstreamAdapter
{
    // ---------------------------------------------------------------
    // 1) 身份：key 会存进 thirdapi_config.adapter，上线后不要改
    // ---------------------------------------------------------------
    public static function key(): string
    {
        return 'demo.minimal';
    }

    public static function label(): string
    {
        return '示例 1 · 最小插件（原样透传）';
    }

    public static function description(): string
    {
        return '教学：从零 implements 接口的最小写法。不做任何适配，选中后行为与「直通」相同。';
    }

    // ---------------------------------------------------------------
    // 2) 请求前钩子：可以改参数名 / 位置 / 编码 / 签名 / 请求头
    //    本例什么都不改，直接返回。
    //    注意 UpstreamRequest 是可变对象，改完要 return 同一个对象。
    // ---------------------------------------------------------------
    public function prepareRequest(UpstreamRequest $request, ThirdApiConfig $upstream): UpstreamRequest
    {
        return $request;
    }

    // ---------------------------------------------------------------
    // 3) 响应后钩子：产出「平台标准结果」
    //    这里能看到完整判断顺序，实际项目里基类已经帮你做了前三步。
    // ---------------------------------------------------------------
    public function parseResponse(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult {
        // ① 请求根本没发出去（DNS / 超时 / 连接被拒）
        if (! $response->isTransportOk()) {
            return UpstreamResult::fail($response->transportError);
        }

        // ② HTTP 非 2xx。一定要先看状态码：上游挂了时响应体是错误页，
        //    直接 json() 会得到 null，以前会静默变成「余额：- 元」这种假成功。
        if (! $response->isHttpOk()) {
            return UpstreamResult::fail(
                '上游返回 HTTP ' . $response->httpStatus,
                ['_raw' => $response->body],
                httpStatus: $response->httpStatus
            );
        }

        // ③ 200 但返回的不是 JSON（代理劫持、网关错误页）
        if (! $response->isJson()) {
            return UpstreamResult::fail(
                '上游返回不是 JSON',
                ['_raw' => $response->body],
                httpStatus: $response->httpStatus
            );
        }

        // ④ 业务层判定。很多厂商是 HTTP 200 + body.code != 0 表示失败，
        //    这一步不做，按钮点击会被判成「提交成功」但其实钱没动。
        //    本例假设厂商没有业务码，直接认为成功；真实插件请照
        //    DemoFlatBalanceAdapter 里 businessOk() 的写法处理。
        $raw = $response->payload();

        // ⑤ 归一到平台标准结构。本例原样返回，
        //    真实插件要把厂商字段翻译成平台字段（见示例 2、4）。
        return UpstreamResult::ok(
            data: $raw,
            raw: $raw,
            httpStatus: $response->httpStatus
        );
    }
}
