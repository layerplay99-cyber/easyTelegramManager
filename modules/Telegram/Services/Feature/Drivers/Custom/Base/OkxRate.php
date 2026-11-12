<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Base;

/**
 * OKX 汇率（/okxr 币种）
 *
 * USDT → 币种，卖家（asks）由低到高取前 10 档。
 * 数据源：OKX 公开行情 GET /api/v5/market/books（现货盘口）。
 */
class OkxRate extends AbstractRateFeature
{
    private const BOOKS_URL = 'https://www.okx.com/api/v5/market/books';

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
        return '查询 OKX 上 USDT → 币种的卖家报价（由低到高前 10 档）。用法：/okxr BTC';
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
                'usage' => '/okxr <币种代码>',
                'description' => '获取 OKX 汇率（USDT→币种卖家价前10）',
                'params' => [
                    ['name' => 'coin', 'required' => true],
                ],
            ],
        ];
    }

    protected function commandName(): string
    {
        return 'okxr';
    }

    protected function fetchAsks(string $coin, int $limit): array
    {
        $data = $this->getJson(self::BOOKS_URL, [
            'instId' => $coin . '-' . self::QUOTE,
            'sz' => $limit,
        ]);

        if ((string) ($data['code'] ?? '1') !== '0') {
            throw new \RuntimeException((string) ($data['msg'] ?? 'OKX 返回异常'));
        }

        // books 返回 data[0] 是现货盘口
        $book = $data['data'][0] ?? [];

        return $this->normalizeLevels($book['asks'] ?? [], $limit);
    }
}
