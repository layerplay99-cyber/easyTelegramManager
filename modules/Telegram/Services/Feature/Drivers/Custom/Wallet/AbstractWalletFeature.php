<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Wallet;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Models\Member;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\WalletService;

/**
 * 钱包功能基类（机器人指令链路）
 *
 * 群里发指令（或私聊机器人）→ 这里 → WalletService → 上游。
 *
 * 后台可配：
 *   - 承接上游（upstream_id）：充值/提现走哪个上游，
 *     该上游的拉单/查单 API 在「上游接口配置」里按接口单独配；
 *   - 币种、回复模板。
 *
 * 安全：身份只认 Telegram 给的 user_id，客户端传什么都没用；
 *      指令链路不发 nonce（Telegram 本身就是可信来源），但金额受后台限额约束，
 *      钱的增减只发生在上游回调验签之后。
 */
abstract class AbstractWalletFeature extends BaseCustomFeature
{
    public static function category(): string
    {
        return 'bot';
    }

    public static function group(): string
    {
        return '钱包';
    }

    public static function triggerName(): string
    {
        return 'command';
    }

    public static function triggers(): array
    {
        return ['command'];
    }

    public static function defaultConfig(): array
    {
        return [
            'currency' => 'CNY',
            'reply_template' => '',
        ];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'upstream_id',
                'label' => '承接上游',
                'type' => 'select',
                'source' => 'third_api_configs',
                'required' => true,
                'hint' => '充值/提现由哪个上游承接；该上游的拉单、查单 API 在「上游接口配置」里配',
            ],
            [
                'key' => 'currency',
                'label' => '币种',
                'type' => 'text',
                'required' => false,
                'default' => 'CNY',
            ],
            [
                'key' => 'reply_template',
                'label' => '回复模板',
                'type' => 'template',
                'required' => false,
                'hint' => '可用变量见各功能说明；留空用默认文案',
            ],
        ];
    }

    /**
     * 当前 Telegram 用户对应的会员（没有则自动开户）
     */
    protected function member(FeatureContext $context): Member
    {
        $userId = (int) ($context->userId ?: data_get($context->payload, 'from.id'));

        if ($userId <= 0) {
            throw new \Exception('无法识别你的身份');
        }

        return app(WalletService::class)->resolveMember($userId, [
            'telegram_username' => data_get($context->payload, 'from.username'),
        ]);
    }

    /**
     * 承接本功能的上游
     */
    protected function upstream(FeatureContext $context): ThirdApiConfig
    {
        return app(WalletService::class)->upstreamFor(
            'custom:' . static::featureKey(),
            (int) ($context->bind?->third_config_id ?: $context->bot?->third_config_id ?: 0)
        );
    }

    /**
     * 币种：后台配置优先，其次指令第二个参数
     */
    protected function currency(FeatureContext $context): string
    {
        return strtoupper((string) ($context->config('currency') ?: ($context->arg(1) ?: 'CNY')));
    }

    /**
     * 金额：指令第一个参数
     */
    protected function amount(FeatureContext $context): float
    {
        $amount = (float) ($context->arg(0) ?: 0);

        if ($amount <= 0) {
            throw new \Exception('金额不正确');
        }

        return $amount;
    }

    /**
     * 有模板就用模板，没有就用默认文案
     */
    protected function renderText(FeatureContext $context, string $defaultText, array $data = []): string
    {
        $template = trim((string) $context->config('reply_template'));

        return $template !== '' ? $this->render($template, $data, $context) : $defaultText;
    }
}
