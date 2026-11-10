<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream\Adapters\Ocean;

use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Upstream\Adapters\BaseUpstreamAdapter;
use Modules\Telegram\Services\Upstream\UpstreamRequest;
use Modules\Telegram\Services\Upstream\UpstreamResponse;
use Modules\Telegram\Services\Upstream\UpstreamResult;

/**
 * ocean测试上游 专用适配器（一条上游一个文件夹的完整案例）
 *
 * ⚠️ 教学用的假上游（api_url 是 example.com），案例约定如下；
 *    接真实上游时换成厂商文档里的字段名和业务码，目录结构和写法不用变。
 *
 *   请求  GET，平台标准参数原样发出：路径占位消耗的参数已被 pathParams
 *         标记，不会重复进 query；鉴权只有 Authorization: Bearer <token>，
 *         由 callEndpoint 统一加，插件不用管（requestHandlers 留空 = 全直通）。
 *   响应  {"code":0,"msg":"ok","data":{...}}；code != 0 视为业务失败（HTTP 仍 200）。
 *   归一  ResponseMap 把厂商字段搬进平台标准结构（见三个 normalize 方法）。
 *
 * 插件只做字段搬运，「商户 / 订单是否存在」等业务结论由功能代码判定
 * （如 BindMerchant 拿标准 data.id 与输入比对），不在这里替业务下结论。
 *
 * ResponseMap.php 是这条上游的响应字段映射，不实现 UpstreamAdapter，
 * 注册表扫描会自动跳过（只有本类会被注册）。
 *
 * 要接需要签名 / 参数放 post-data 的接口，在 requestHandlers 登记并写
 * handler（sendParamsInBody() / setBodyMode() / 签名），写法见
 * docs/upstream-adapter.md 与 Demo 两个示例。
 *
 * 只服务 ocean测试上游 这一条上游，不要给别的上游选它；
 * 别家的字段不要加进 ResponseMap，另建一个文件夹。
 */
class OceanAdapter extends BaseUpstreamAdapter
{
    // ---------------------------------------------------------------
    // 1) 身份：key 存进 thirdapi_config.adapter，上线后不要改
    // ---------------------------------------------------------------
    public static function key(): string
    {
        return 'ocean.test';
    }

    public static function label(): string
    {
        return 'ocean测试上游 · 专用适配器';
    }

    public static function description(): string
    {
        return '教学案例：只服务 ocean测试上游。请求按平台标准参数直发（鉴权走 token），响应判 code!=0，并把 available_funds / currency.code 归一成平台标准。';
    }

    // ---------------------------------------------------------------
    // 2) 声明表：新增接口只加一行，不用写 if / match 判断 endpointCode
    //    请求侧全部直通（鉴权只靠 token），所以只登记响应侧
    // ---------------------------------------------------------------
    protected function responseHandlers(): array
    {
        return [
            'merchant.balance' => 'normalizeBalance',
            'merchant.check' => 'normalizeCheck',
            'merchant.trade.query' => 'normalizeQuery',
        ];
    }

    // ---------------------------------------------------------------
    // 3) 所有接口公共：业务码判定（响应侧）
    //    HTTP 200 + code != 0 也是失败，必须判，否则按钮会误判成功
    // ---------------------------------------------------------------
    protected function businessFailure(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): ?UpstreamResult {
        if ($this->businessOk($response, ['code'], [0, '0', 'SUCCESS'])) {
            return null;
        }

        return UpstreamResult::fail(
            $this->businessMessage($response),
            $response->payload(),
            $this->businessCode($response),
            $response->httpStatus
        );
    }

    protected function normalizeBalance(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult {
        // data 是平台标准结构；raw 保留厂商原文（模板里 {{_raw}} 能看）
        return UpstreamResult::ok(
            ResponseMap::balance($response),
            $response->payload(),
            $this->businessCode($response),
            $response->httpStatus
        );
    }

    protected function normalizeCheck(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult {
        return UpstreamResult::ok(
            ResponseMap::check($response),
            $response->payload(),
            $this->businessCode($response),
            $response->httpStatus
        );
    }

    protected function normalizeQuery(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult {
        return UpstreamResult::ok(
            ResponseMap::tradeQuery($response),
            $response->payload(),
            $this->businessCode($response),
            $response->httpStatus
        );
    }
}
