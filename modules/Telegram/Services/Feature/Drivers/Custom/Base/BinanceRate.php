<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Base;

/**
 * 币安 C2C 汇率（/bnr [法币]）
 *
 * USDT → 法币的卖家挂单，价格由低到高前 10 条。
 * 数据源：币安 C2C 商家挂单 POST /bapi/c2c/v2/friendly/c2c/adv/search
 * （tradeType=BUY 表示「我要买 U」，返回的是卖家出价，取最低的 10 个）
 */
class BinanceRate extends AbstractRateFeature
{
    private const SEARCH_URL = 'https://p2p.binance.com/bapi/c2c/v2/friendly/c2c/adv/search';

    public static function key(): string
    {
        return 'custom.base.binance_rate';
    }

    public static function featureName(): string
    {
        return '币安汇率查询';
    }

    public static function featureDescription(): string
    {
        return '查询币安 C2C 上 USDT → 法币的卖家报价（由低到高前 10 条）。用法：/bnr CNY（默认 CNY）';
    }

    public static function uuid(): string
    {
        return 'binanceRate';
    }

    public static function commands(): array
    {
        return [
            [
                'command' => '/bnr',
                'usage' => '/bnr [法币]',
                'description' => '获取币安 C2C 汇率（USDT→法币卖家价前10，默认 CNY）',
                'params' => [
                    ['name' => 'fiat', 'required' => false, 'hint' => '默认 CNY'],
                ],
            ],
        ];
    }

    protected function commandName(): string
    {
        return 'bnr';
    }

    protected function fetchOffers(string $fiat, int $limit): array
    {
        $json = $this->requestJson('POST', self::SEARCH_URL, [
            'asset' => self::ASSET,
            'fiat' => $fiat,
            'merchantCheck' => false,
            'page' => 1,
            'payTypes' => [],
            'publisherType' => null,
            'rows' => 20,
            'tradeType' => 'BUY',
            'transAmount' => '',
        ], [
            'Content-Type' => 'application/json',
        ]);

        if ((string) ($json['code'] ?? '') !== '000000') {
            throw new \RuntimeException((string) ($json['message'] ?? '币安返回异常'));
        }

        $offers = [];

        foreach ((array) ($json['data'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $adv = (array) ($item['adv'] ?? []);
            $advertiser = (array) ($item['advertiser'] ?? []);

            $price = (float) ($adv['price'] ?? 0);

            if ($price <= 0) {
                continue;
            }

            $offers[] = [
                'price' => $price,
                'available' => (float) ($adv['surplusAmount'] ?? $adv['tradableQuantity'] ?? 0),
                'min' => (float) ($adv['minSingleTransAmount'] ?? 0),
                'max' => (float) ($adv['maxSingleTransAmount'] ?? 0),
                'merchant' => (string) ($advertiser['nickName'] ?? ''),
            ];
        }

        return array_slice($this->sortAsc($offers), 0, $limit);
    }
}
