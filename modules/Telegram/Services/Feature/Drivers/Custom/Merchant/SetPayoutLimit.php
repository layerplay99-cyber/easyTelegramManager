<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * /xiane <金额> —— 设置自动代付限额（超过该金额需二次确认）
 *
 * 上游：POST /api/webhook/user/limit/set  With Token，JSON body
 *   {"user_id": "<商户ID>", "limit_money": "<金额>"}
 *
 * 金额传 0 表示取消二次确认，走单独一条文案；两条文案都按群语种取自语言包。
 */
class SetPayoutLimit extends AbstractMerchantFeature implements EndpointProvider
{
    private const ENDPOINT = 'merchant.limit.set';

    public static function key(): string
    {
        return 'custom.set_payout_limit';
    }

    public static function label(): string
    {
        return '设置自动代付限额';
    }

    public static function featureName(): string
    {
        return '设置自动代付限额';
    }

    public static function featureDescription(): string
    {
        return '设置代付订单二次确认的金额门槛，0 为取消，按群语种回复（/xiane <金额>）';
    }

    public static function endpoints(): array
    {
        return [
            'merchant.limit.set' => [
                'name' => '设置自动代付限额',
                'method' => 'POST',
                'path_template' => 'api/webhook/user/limit/set',
                'params_schema' => [
                    ['name' => 'user_id', 'required' => true, 'desc' => '商户ID', 'map_to' => 'merchant_id'],
                    ['name' => 'limit_money', 'required' => true, 'desc' => '限额金额，0 表示取消二次确认'],
                ],
                'response_schema' => [
                    'user_id' => '商户ID',
                    'limit_money' => '限额金额',
                ],
                'remark' => 'JSON body；With Token',
            ],
        ];
    }

    public static function commands(): array
    {
        return [
            [
                'command' => 'xiane',
                'usage' => '/xiane <金额>',
                'description' => '设置代付订单二次确认限额，0 为取消（按群语种回复）',
                // 校验放在 handle()（amountArg），错误文案走语言包
                'params' => [
                    ['name' => 'limit_money', 'desc' => '限额金额'],
                ],
            ],
        ];
    }

    public static function defaultConfig(): array
    {
        return static::commonDefaultConfig(self::ENDPOINT);
    }

    public static function configSchema(): array
    {
        return static::commonConfigSchema(self::ENDPOINT, '默认 POST api/webhook/user/limit/set，JSON body');
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->resolveMerchantId($context);

        if ($merchantId === '') {
            return $this->localizedFail($this->notBoundTip($context));
        }

        $limitMoney = $this->amountArg($context);

        if ($limitMoney === null) {
            return $this->localizedFail($this->t($context, 'limit_set.usage'));
        }

        $data = $this->callUpstream($context, self::ENDPOINT, [
            'user_id' => $merchantId,
            'limit_money' => $limitMoney,
        ], 'POST');

        if (isset($data['_error'])) {
            return $this->localizedFail($this->t($context, 'limit_set.failed') . $data['_error']);
        }

        // 0 = 取消二次确认，文案与「设置了某个金额」完全不同
        $text = (float) $limitMoney === 0.0
            ? $this->t($context, 'limit_set.zero')
            : $this->t($context, 'limit_set.success', ['amount' => $limitMoney]);

        return $this->localizedReply($text, $data);
    }
}
