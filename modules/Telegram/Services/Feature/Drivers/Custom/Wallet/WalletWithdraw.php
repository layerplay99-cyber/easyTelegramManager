<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Wallet;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\WalletService;

/**
 * 提现
 *
 * 用法：/tx 100
 *
 * 先冻结余额再发上游：上游接不住就自动解冻，钱不会凭空消失。
 */
class WalletWithdraw extends AbstractWalletFeature
{
    public static function key(): string
    {
        return 'wallet.withdraw';
    }

    public static function label(): string
    {
        return '钱包·提现';
    }

    public static function featureKey(): string
    {
        return 'walletWithdraw';
    }

    public static function featureName(): string
    {
        return '提现';
    }

    public static function featureDescription(): string
    {
        return '发起提现，先冻结余额再由上游代付';
    }

    public static function commands(): array
    {
        return [
            [
                'command' => 'tx',
                'usage' => '/tx 金额',
                'description' => '发起提现，例：/tx 100',
                'params' => [
                    ['name' => 'amount', 'required' => true, 'description' => '提现金额'],
                ],
                'reply_template' => '',
            ],
        ];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        try {
            $member = $this->member($context);
            $currency = $this->currency($context);
            $amount = $this->amount($context);
            $upstream = $this->upstream($context);

            $order = app(WalletService::class)->createWithdraw($member, $currency, $amount, $upstream, [
                'skip_replay_check' => true,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $data = $order + ['currency' => $currency];

        $text = $this->renderText(
            $context,
            sprintf("📤 提现单 %s\n金额：%s %s\n已提交上游处理", $order['order_no'], number_format($amount, 2), $currency),
            $data
        );

        return $this->reply($text, $data);
    }
}
