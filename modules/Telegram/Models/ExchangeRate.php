<?php

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

class ExchangeRate extends Model
{
    protected $table = 'exchange_rates';

    protected $fillable = [
        'from_currency', 'to_currency', 'rate', 'buy_rate', 'sell_rate',
        'auto_update', 'source', 'status'
    ];

    protected $casts = [
        'rate' => 'decimal:8',
        'buy_rate' => 'decimal:8',
        'sell_rate' => 'decimal:8',
    ];

    /**
     * 获取汇率
     * @param string $fromCurrency 源币种
     * @param string $toCurrency 目标币种
     * @param string|null $type buy=买入(充值), sell=卖出(提现), null=中间价
     * @return float|null
     */
    public static function getRate(string $fromCurrency, string $toCurrency, ?string $type = null): ?float
    {
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        $rate = self::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('status', 1)
            ->first();

        if (!$rate) {
            return null;
        }

        if ($type === 'buy' && $rate->buy_rate) {
            return (float) $rate->buy_rate;
        }

        if ($type === 'sell' && $rate->sell_rate) {
            return (float) $rate->sell_rate;
        }

        return (float) $rate->rate;
    }

    /**
     * 货币转换
     * @param float $amount 金额
     * @param string $fromCurrency 源币种
     * @param string $toCurrency 目标币种
     * @param string|null $type 汇率类型
     * @return float|null
     */
    public static function convert(float $amount, string $fromCurrency, string $toCurrency, ?string $type = null): ?float
    {
        $rate = self::getRate($fromCurrency, $toCurrency, $type);

        if ($rate === null) {
            return null;
        }

        return round($amount * $rate, 8);
    }
}

