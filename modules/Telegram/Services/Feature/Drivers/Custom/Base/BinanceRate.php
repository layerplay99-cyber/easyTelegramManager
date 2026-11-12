<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Base;

/**
 * 币安汇率（/bnr 币种）
 *
 * USDT → 币种，卖家（asks）由低到高取前 10 档。
 * 数据源：币安公开行情 GET /api/v3/depth（现货盘口）。
 */
class BinanceRate extends AbstractRateFeature
{
    private const DEPTH_URL = 'https://api.binance.com/api/v3/depth';

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
        return '查询币安上 USDT → 币种的卖家报价（由低到高前 10 档）。用法：/bnr BTC';
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
                'usage' => '/bnr <币种代码>',
                'description' => '获取币安汇率（USDT→币种卖家价前10）',
                'params' => [
                    ['name' => 'coin', 'required' => true],
                ],
            ],
        ];
    }

    protected function commandName(): string
    {
        return 'bnr';
    }

    protected function fetchAsks(string $coin, int $limit): array
    {
        $data = $this->getJson(self::DEPTH_URL, [
            'symbol' => $coin . self::QUOTE,
            'limit' => $limit,
        ]);

        return $this->normalizeLevels($data['asks'] ?? [], $limit);
    }
}
