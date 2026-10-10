<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Wallet;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\WalletService;

/**
 * 查询钱包余额
 *
 * 平台侧余额为准（钱存在平台钱包里，上游余额仅供参考）。
 */
class WalletBalance extends AbstractWalletFeature
{
    public static function key(): string
    {
        return 'wallet.balance';
    }

    public static function label(): string
    {
        return '钱包·查询余额';
    }

    public static function featureKey(): string
    {
        return 'walletBalance';
    }

    public static function featureName(): string
    {
        return '查询余额';
    }

    public static function featureDescription(): string
    {
        return '查询自己的钱包余额（可用 + 冻结）';
    }

    public static function commands(): array
    {
        return [
            [
                'command' => 'yue',
                'usage' => '/yue',
                'description' => '查询钱包余额',
                'params' => [],
                'reply_template' => '',
            ],
        ];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        try {
            $member = $this->member($context);
            $currency = $this->currency($context);
            $balance = app(WalletService::class)->balance($member, $currency);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $text = $this->renderText(
            $context,
            sprintf(
                "💰 %s 钱包\n可用：%s\n冻结：%s",
                $currency,
                number_format($balance['balance'], 2),
                number_format($balance['frozen'], 2)
            ),
            $balance
        );

        return $this->reply($text, $balance);
    }
}
