<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Wallet;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\WalletService;

/**
 * 我的账单（最近充值/提现单）
 */
class WalletBill extends AbstractWalletFeature
{
    public static function key(): string
    {
        return 'wallet.bill';
    }

    public static function label(): string
    {
        return '钱包·我的账单';
    }

    public static function featureKey(): string
    {
        return 'walletBill';
    }

    public static function featureName(): string
    {
        return '我的账单';
    }

    public static function featureDescription(): string
    {
        return '查看最近的充值/提现订单';
    }

    public static function commands(): array
    {
        return [
            [
                'command' => 'zd',
                'usage' => '/zd',
                'description' => '查看最近账单',
                'params' => [],
                'reply_template' => '',
            ],
        ];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        try {
            $member = $this->member($context);
            $orders = app(WalletService::class)->recentOrders($member->id, (int) ($context->config('limit') ?: 10));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        if ($orders === []) {
            return $this->reply('还没有账单记录');
        }

        $lines = ['📒 最近账单：'];

        foreach ($orders as $order) {
            $lines[] = sprintf(
                '%s %s %s %s · %s',
                $order['type'] === 'recharge' ? '⬇️充值' : '⬆️提现',
                number_format($order['amount'], 2),
                $order['currency'],
                $order['status_text'],
                $order['order_no']
            );
        }

        return $this->reply($this->renderText($context, implode("\n", $lines), ['orders' => $orders]));
    }
}
