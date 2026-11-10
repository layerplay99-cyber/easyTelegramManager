<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream\Adapters\Demo;

use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Upstream\Adapters\BaseUpstreamAdapter;
use Modules\Telegram\Services\Upstream\UpstreamRequest;
use Modules\Telegram\Services\Upstream\UpstreamResponse;
use Modules\Telegram\Services\Upstream\UpstreamResult;

/**
 * 【教学示例 3 / 4】只改「请求」：字段改名 + form 编码 + 签名 + 私有头
 *
 * 假设「示例乙」是个老支付系统，接口文档要求：
 *
 *   POST /api/order/create        Content-Type: application/x-www-form-urlencoded
 *   Header: X-Mch-Id: <商户号>
 *   Body:   mchOrderNo=平台订单号&amt=金额(分)&ts=时间戳&nonce=随机串&sign=大写MD5签名
 *
 * 而平台功能代码只传标准参数：
 *   ['merchant_id' => '1001', 'order_no' => 'DD123', 'amount' => '12.34']
 *
 * 这个插件负责把前者从后者翻译出来，功能代码一行都不用改。
 *
 * 覆盖了实际对接里最常见的四类请求差异：
 *   ① 参数名不一样     renameParams()
 *   ② 参数位置不一样   moveParamToHeader() / moveParamsToQuery()
 *   ③ 编码方式不一样   setBodyMode('form')
 *   ④ 签名规则不一样   自己拼串 + hash
 *
 * ⚠️ 教学示例，不要在生产环境的三方配置里选中它。
 */
class DemoLegacyFormAdapter extends BaseUpstreamAdapter
{
    public static function key(): string
    {
        return 'demo.legacy_form';
    }

    public static function label(): string
    {
        return '示例 3 · 老式表单签名（示例乙）';
    }

    public static function description(): string
    {
        return '教学：只覆写 prepareRequest()，演示字段改名、参数放 header、form 编码、自定义签名。';
    }

    public function prepareRequest(UpstreamRequest $request, ThirdApiConfig $upstream): UpstreamRequest
    {
        // ⓪ 先留一份「平台原始订单号」：下面会把它改名成 mchOrderNo，
        //    而厂商响应里未必回传订单号，parseResponse 要用它填标准结构。
        //    remember()/recalled() 就是给这种「请求侧 → 响应侧」传值用的。
        $request->remember('original_order_no', $request->params['order_no'] ?? '');

        // ① 平台标准名 → 厂商私有名
        //    注意：只重命名确实存在的键；pathParams 会跟着改，
        //    避免同一个参数既进了 URL 路径又进一遍 query。
        $request->renameParams([
            'merchant_id' => 'mchId',
            'order_no' => 'mchOrderNo',
            'amount' => 'amt',
            'trade_id' => 'mchTradeNo',
        ]);

        // ② 金额：平台用「元」字符串，厂商要「分」整数
        if ($request->hasParam('amt')) {
            $request->setParam('amt', (int) round(((float) $request->params['amt']) * 100));
        }

        // ③ 商户号放在私有头里，而不是 body
        $request->moveParamToHeader('mchId', 'X-Mch-Id');

        // ④ 固定参数与时间戳 / 随机串
        $request
            ->setParam('ts', time())
            ->setParam('nonce', bin2hex(random_bytes(8)))
            ->setParam('version', '1.0');

        // ⑤ 签名：这里演示「字典序拼接 + key 后缀 + MD5 大写」，
        //    真实项目请按厂商文档替换。凭证从 $upstream 取，不要写死。
        $payload = $request->params;
        ksort($payload);
        $parts = [];
        foreach ($payload as $k => $v) {
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $parts[] = $k . '=' . $v;
        }
        $secret = (string) ($upstream->secrept_key ?? '');
        $request->setParam('sign', strtoupper(md5(implode('&', $parts) . '&key=' . $secret)));

        // ⑥ 编码方式 + 请求方法
        $request
            ->setBodyMode('form')
            ->setMethod('POST');

        return $request;
    }

    /**
     * 响应也顺手演示一下：本厂商用 ret=1 表示成功，状态是中文。
     * 基类默认只看 HTTP，这里补上业务码判定，避免 200 + 业务失败被判成功。
     */
    protected function normalize(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult {
        $raw = $response->payload();

        if (! $this->businessOk($response, ['ret', 'code'], [1, '1', 0, '0', 'SUCCESS'])) {
            return UpstreamResult::fail(
                $this->businessMessage($response),
                $raw,
                $this->businessCode($response),
                $response->httpStatus
            );
        }

        // 归一到标准结构：状态值用平台统一枚举
        $data = $this->unwrap($response);
        $standard = [
            'data' => [
                'status' => $this->mapStatus($data['state'] ?? $data['status'] ?? ''),
                'order_no' => $data['mchOrderNo']
                    ?? $request->recalled('original_order_no')
                    ?? $request->params['mchOrderNo']
                    ?? '',
                'amount' => $this->fenToYuan($data['amt'] ?? null),
            ],
        ];

        return UpstreamResult::ok($standard, $raw, httpStatus: $response->httpStatus);
    }

    /**
     * 厂商状态值 → 平台状态枚举（pending / success / failed）
     */
    private function mapStatus(mixed $value): string
    {
        return match (strtolower(trim((string) $value))) {
            '支付成功', '已支付', 'paid', 'success', 'completed' => 'success',
            '支付失败', '已取消', 'failed', 'closed', 'cancel' => 'failed',
            default => 'pending',
        };
    }
}
