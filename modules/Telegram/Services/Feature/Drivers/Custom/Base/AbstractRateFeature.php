<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Base;

use Illuminate\Support\Facades\Http;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * 交易所汇率命令基类（OKX / 币安共用）
 *
 * 「USDT → 币种」的卖家报价，由低到高取前 10 位：
 * 现货盘口的卖单（asks）本身就是升序挂单，直接取前 10 档。
 * 行情是交易所公开接口，直连查询，不经三方上游配置。
 */
abstract class AbstractRateFeature extends AbstractBaseFeature
{
    /** 计价币种固定 USDT */
    protected const QUOTE = 'USDT';

    public function handle(FeatureContext $context): FeatureResult
    {
        $coin = strtoupper(trim((string) $context->arg(0, '')));

        if ($coin === '') {
            return FeatureResult::reply('请带上币种代码，例如：/' . $this->commandName() . ' BTC');
        }

        if (! preg_match('/^[A-Z0-9]{2,10}$/', $coin)) {
            return FeatureResult::reply('币种代码格式不正确');
        }

        if ($coin === self::QUOTE) {
            return FeatureResult::reply('计价币种就是 ' . self::QUOTE . '，请换成其它币种');
        }

        try {
            $asks = $this->fetchAsks($coin, 10);
        } catch (\Throwable $e) {
            return FeatureResult::fail('行情查询失败：' . $e->getMessage());
        }

        if ($asks === []) {
            return FeatureResult::reply("未查询到 {$coin}/" . self::QUOTE . " 的卖家挂单，请确认币种代码");
        }

        $lines = [sprintf('%s / %s 卖家报价（由低到高，前 %d 档）：', $coin, self::QUOTE, count($asks))];

        foreach ($asks as $i => [$price, $qty]) {
            $lines[] = sprintf('%d. %s  ×  %s', $i + 1, $price, $qty);
        }

        return FeatureResult::reply(implode("\n", $lines));
    }

    /**
     * 拉取卖单挂档，返回 [[价格, 数量], ...]（升序前 $limit 档）
     *
     * @return array<int, array{0: string, 1: string}>
     */
    abstract protected function fetchAsks(string $coin, int $limit): array;

    abstract protected function commandName(): string;

    /**
     * 公开行情 GET（统一超时与失败语义）
     *
     * @return array<string, mixed>
     */
    protected function getJson(string $url, array $query = []): array
    {
        $response = Http::timeout(8)->retry(1, 300)->get($url, $query);

        if ($response->failed()) {
            throw new \RuntimeException('交易所接口返回 ' . $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * 盘口原始档位 [[px, qty, ...], ...] → [[px, qty], ...]
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected function normalizeLevels(mixed $raw, int $limit): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $levels = [];

        foreach (array_slice($raw, 0, $limit) as $level) {
            if (is_array($level) && isset($level[0], $level[1])) {
                $levels[] = [(string) $level[0], (string) $level[1]];
            }
        }

        return $levels;
    }
}
