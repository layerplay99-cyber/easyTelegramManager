<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Base;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * 交易所 C2C 汇率命令基类（OKX / 币安共用）
 *
 * 口径：USDT → 法币的**卖家**报价（商家挂单），价格由低到高取前 10 位。
 * 参数是法币代码（CNY / USD / RUB …），留空默认 CNY —— 这才是场外交易里
 * 大家看的「汇率」，现货盘口的 BTC/USDT 卖单跟它不是一个东西。
 *
 * 两家都是公开接口，直连查询，不经三方上游配置；结果缓存 60 秒，
 * 避免群里连着刷把上游限流打穿。
 */
abstract class AbstractRateFeature extends AbstractBaseFeature
{
    /** 交易的加密币种固定 USDT */
    protected const ASSET = 'USDT';

    /** 不带参数时的法币 */
    protected const DEFAULT_FIAT = 'CNY';

    protected const CACHE_TTL = 60;

    public function handle(FeatureContext $context): FeatureResult
    {
        $fiat = strtoupper(trim((string) $context->arg(0, '')));

        if ($fiat === '') {
            $fiat = self::DEFAULT_FIAT;
        }

        if (! preg_match('/^[A-Z]{2,5}$/', $fiat)) {
            return FeatureResult::reply('法币代码格式不正确（例如 CNY / USD / RUB）');
        }

        try {
            $offers = Cache::remember(
                $this->cacheKey($fiat),
                self::CACHE_TTL,
                fn (): array => $this->fetchOffers($fiat, 10)
            );
        } catch (\Throwable $e) {
            return FeatureResult::fail('行情查询失败：' . $e->getMessage());
        }

        if ($offers === []) {
            return FeatureResult::reply(
                sprintf('未查询到 %s/%s 的卖家挂单，请确认法币代码（例如 /%s USD）', self::ASSET, $fiat, $this->commandName())
            );
        }

        $lines = [sprintf('%s / %s 卖家报价（由低到高，前 %d 档）：', self::ASSET, $fiat, count($offers))];

        foreach ($offers as $i => $offer) {
            $lines[] = $this->renderLine($i + 1, $offer, $fiat);
        }

        return FeatureResult::reply(implode("\n", $lines));
    }

    /**
     * 拉卖家挂单，价格升序取前 $limit 条
     *
     * 单条结构：['price' => float, 'available' => float, 'min' => float, 'max' => float, 'merchant' => string]
     * min/max 是单笔限额（法币），缺失填 0；merchant 没有就留空。
     *
     * @return array<int, array{price: float, available: float, min: float, max: float, merchant: string}>
     */
    abstract protected function fetchOffers(string $fiat, int $limit): array;

    abstract protected function commandName(): string;

    protected function cacheKey(string $fiat): string
    {
        return sprintf('tg_rate:%s:%s_%s_top10', $this->commandName(), self::ASSET, $fiat);
    }

    /**
     * 一行报价：价格 + 可用量 + 限额 + 商家
     *
     * @param array{price: float, available: float, min: float, max: float, merchant: string} $offer
     */
    protected function renderLine(int $index, array $offer, string $fiat): string
    {
        $line = sprintf('%d. %s %s × 可用 %s %s', $index, $this->number($offer['price'], 4), $fiat, $this->number($offer['available'], 2), self::ASSET);

        $min = (float) ($offer['min'] ?? 0);
        $max = (float) ($offer['max'] ?? 0);

        if ($min > 0 && $max > 0 && abs($min - $max) < 0.01) {
            // 商家把单笔固定在同一个金额（OKX 常见），显示成一个数别写成 a ~ a
            $line .= ' ｜ 单笔 ' . $this->number($min, 2);
        } elseif ($min > 0 || $max > 0) {
            $line .= sprintf(' ｜ 限额 %s ~ %s', $this->number($min, 2), $this->number($max, 2));
        }

        $merchant = trim((string) ($offer['merchant'] ?? ''));

        if ($merchant !== '') {
            $line .= ' ｜ ' . $merchant;
        }

        return $line;
    }

    /** 去掉无意义的小数 0，并对大数字加千分位 */
    protected function number(float $value, int $decimals): string
    {
        $text = rtrim(rtrim(number_format($value, $decimals, '.', ','), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /**
     * 按价格升序排序（两家返回顺序都不保证）
     *
     * @param array<int, array{price: float}> $offers
     * @return array<int, array{price: float, available: float, min: float, max: float, merchant: string}>
     */
    protected function sortAsc(array $offers): array
    {
        usort($offers, static fn (array $a, array $b): int => $a['price'] <=> $b['price']);

        return $offers;
    }

    /**
     * 公开 JSON 接口（统一超时与失败语义）
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    protected function requestJson(string $method, string $url, array $payload = [], array $headers = []): array
    {
        $request = Http::timeout(8)
            ->retry(1, 300)
            ->withHeaders($headers + [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ]);

        $response = strtoupper($method) === 'POST'
            ? $request->post($url, $payload)
            : $request->get($url, $payload);

        if ($response->failed()) {
            throw new \RuntimeException('交易所接口返回 ' . $response->status());
        }

        return $response->json() ?? [];
    }
}
