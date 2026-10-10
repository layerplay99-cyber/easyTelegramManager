<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Wallet;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\WalletService;

/**
 * 充值
 *
 * 用法：/cz 100
 *
 * 只负责"下单"，不给用户加钱：钱要等上游回调验签通过后才入账，
 * 所以任何绕过上游直接调接口的行为都拿不到余额。
 */
class WalletRecharge extends AbstractWalletFeature
{
    public static function key(): string
    {
        return 'wallet.recharge';
    }

    public static function label(): string
    {
        return '钱包·充值';
    }

    public static function featureKey(): string
    {
        return 'walletRecharge';
    }

    public static function featureName(): string
    {
        return '充值';
    }

    public static function featureDescription(): string
    {
        return '发起充值，返回支付链接（到账以回调为准）';
    }

    public static function commands(): array
    {
        return [
            [
                'command' => 'cz',
                'usage' => '/cz 金额',
                'description' => '发起充值，例：/cz 100',
                'params' => [
                    ['name' => 'amount', 'required' => true, 'description' => '充值金额'],
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

            $order = app(WalletService::class)->createRecharge($member, $currency, $amount, $upstream, [
                // 指令来自 Telegram，身份本身可信，不再要求 nonce
                'skip_replay_check' => true,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $data = $order + ['currency' => $currency];

        $text = $this->renderText(
            $context,
            $order['pay_url']
                ? sprintf("💳 充值单 %s\n金额：%s %s\n请点击支付：%s", $order['order_no'], number_format($amount, 2), $currency, $order['pay_url'])
                : sprintf("⚠️ 充值单 %s 已创建，但上游未返回支付链接，请稍后重试或联系客服", $order['order_no']),
            $data
        );

        return $this->reply($text, $data);
    }
}
