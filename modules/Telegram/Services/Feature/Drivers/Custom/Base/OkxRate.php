<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Base;

/**
 * OKX C2C 汇率（/okxr [法币]）
 *
 * USDT → 法币的卖家挂单，价格由低到高前 10 条。
 * 数据源：OKX C2C GET /v3/c2c/tradingOrders/books
 * （side=sell = 商家卖出 USDT，即我们要看的卖家报价）
 */
class OkxRate extends AbstractRateFeature
{
    private const BOOKS_URL = 'https://www.okx.com/v3/c2c/tradingOrders/books';

    public static function key(): string
    {
        return 'custom.base.okx_rate';
    }

    public static function featureName(): string
    {
        return 'OKX 汇率查询';
    }

    public static function featureDescription(): string
    {
        return '查询 OKX C2C 上 USDT → 法币的卖家报价（由低到高前 10 条）。用法：/okxr CNY（默认 CNY）';
    }

    public static function uuid(): string
    {
        return 'okxRate';
    }

    public static function commands(): array
    {
        return [
            [
                'command' => '/okxr',
                'usage' => '/okxr [法币]',
                'description' => '获取 OKX C2C 汇率（USDT→法币卖家价前10，默认 CNY）',
                'params' => [
                    ['name' => 'fiat', 'required' => false, 'hint' => '默认 CNY'],
                ],
            ],
        ];
    }

    protected function commandName(): string
    {
        return 'okxr';
    }

    protected function fetchOffers(string $fiat, int $limit): array
    {
        $json = $this->requestJson('GET', self::BOOKS_URL, [
            'side' => 'sell',
            'baseCurrency' => self::ASSET,
            'quoteCurrency' => $fiat,
            'paymentMethod' => 'all',
            'userType' => 'all',
            // 接口要求毫秒时间戳，不做这个会被 CDN 判定为缓存/爬虫
            't' => (int) (microtime(true) * 1000),
        ], [
            'Origin' => 'https://www.okx.com',
            'Referer' => 'https://www.okx.com/',
        ]);

        $list = (array) ($json['data']['sell'] ?? []);

        if ($list === []) {
            // OKX 用 code 表达业务错误，data 为空时把 code 带出去便于排查
            $code = (string) ($json['code'] ?? '0');

            if ($code !== '' && $code !== '0') {
                throw new \RuntimeException('OKX 返回 code=' . $code);
            }

            return [];
        }

        $offers = [];

        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }

            $price = (float) ($item['price'] ?? 0);

            if ($price <= 0) {
                continue;
            }

            $offers[] = [
                'price' => $price,
                'available' => (float) ($item['available'] ?? $item['availableAmount'] ?? 0),
                'min' => (float) ($item['quoteMinAmountPerOrder'] ?? $item['minAmount'] ?? 0),
                'max' => (float) ($item['quoteMaxAmountPerOrder'] ?? $item['maxAmount'] ?? 0),
                'merchant' => (string) ($item['nickName'] ?? $item['merchantName'] ?? ''),
            ];
        }

        return array_slice($this->sortAsc($offers), 0, $limit);
    }
}
