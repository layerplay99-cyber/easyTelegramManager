<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream\Adapters\Ocean;

use Modules\Telegram\Services\Upstream\UpstreamResponse;

/**
 * ocean测试上游 的响应字段映射（案例）
 *
 * 只写这一条上游的字段名。别的上游返回 balance / avl / result.wallet，
 * 那是它们自己的事，请在各自的文件夹里写，不要往这里加 ?? 候选链。
 *
 * 不实现 UpstreamAdapter，注册表扫描会自动跳过。
 */
class ResponseMap
{
    /**
     * 余额接口：这条上游的钱包结构 → 平台标准结构
     *
     *   {"data":{"available_funds":"978479062.00","currency":{"code":"VND"}}}
     *     → {"data":{"balance":"978479062.00","currency":"VND"}}
     *
     * @return array<string, mixed>
     */
    public static function balance(UpstreamResponse $response): array
    {
        $wallet = $response->get('data');
        $wallet = is_array($wallet) ? $wallet : $response->payload();

        // 厂商给的是币种对象，平台标准要字符串
        $currency = $wallet['currency'] ?? null;

        if (is_array($currency)) {
            $currency = $currency['code'] ?? $currency['symbol'] ?? null;
        }

        return [
            'data' => [
                'balance' => $wallet['available_funds'] ?? null,
                'currency' => $currency,
            ],
        ];
    }

    /**
     * 校验商户接口：这条上游的响应 → 平台标准结构 {"data":{"id":...}}
     *
     * 插件只做字段搬运：把厂商返回的商户号放到 data.id。
     * 这个 id 是否等于命令里输入的商户号，由 BindMerchant 判定 ——
     * 插件不认识用户的输入，也不该替业务决定「商户是否存在」。
     */
    public static function check(UpstreamResponse $response): array
    {
        $data = $response->get('data');
        $data = is_array($data) ? $data : $response->payload();

        if (! isset($data['id'])) {
            return ['data' => []];
        }

        return ['data' => ['id' => $data['id']]];
    }

    /**
     * 查单接口：data 里没有 reference_id 视为「未查到订单」，返回空 data；
     * 有则整个 data 原样透出，字段由功能侧按需读取。
     */
    public static function tradeQuery(UpstreamResponse $response): array
    {
        $data = $response->get('data');
        $data = is_array($data) ? $data : $response->payload();

        if(! isset($data['reference_id'])){
            return ['data' => []];
        }

        return ['data' => $data];
    }
}
