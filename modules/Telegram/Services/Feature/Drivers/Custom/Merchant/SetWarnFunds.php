<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * /yujing <金额> —— 设置余额不足提醒（预警线）
 *
 * 上游：POST /api/webhook/users/warn/funds  With Token，JSON body
 *   {"user_id": "<商户ID>", "warn_funds": "<金额>"}
 *
 * 回复按输入金额渲染，不取上游返回的 warn_funds —— 上游响应里这个字段可能是
 * NULL（异步生效），群里要看到的是本次实际提交的值。文案按群语种取自语言包。
 */
class SetWarnFunds extends AbstractMerchantFeature implements EndpointProvider
{
    private const ENDPOINT = 'merchant.warn.funds';

    public static function key(): string
    {
        return 'custom.set_warn_funds';
    }

    public static function label(): string
    {
        return '设置余额不足提醒';
    }

    public static function featureName(): string
    {
        return '设置余额不足提醒';
    }

    public static function featureDescription(): string
    {
        return '设置当前群绑定商户的余额预警线，按群语种回复（/yujing <金额>）';
    }

    public static function endpoints(): array
    {
        return [
            'merchant.warn.funds' => [
                'name' => '设置余额不足提醒',
                'method' => 'POST',
                'path_template' => 'api/webhook/users/warn/funds',
                'params_schema' => [
                    ['name' => 'user_id', 'required' => true, 'desc' => '商户ID', 'map_to' => 'merchant_id'],
                    ['name' => 'warn_funds', 'required' => true, 'desc' => '预警金额'],
                ],
                'response_schema' => [
                    'data.id' => '记录ID',
                    'data.available_funds' => '可用余额',
                    'data.locked_funds' => '冻结余额',
                    'data.warn_funds' => '预警线（可能为 NULL）',
                    'data.currency.code' => '币种代码',
                    'data.currency.symbol' => '币种符号',
                ],
                'remark' => 'JSON body；With Token',
            ],
        ];
    }

    public static function commands(): array
    {
        return [
            [
                'command' => 'yujing',
                'usage' => '/yujing <金额>',
                'description' => '设置余额不足提醒的预警金额（按群语种回复）',
                // 校验放在 handle()（amountArg），错误文案走语言包
                'params' => [
                    ['name' => 'warn_funds', 'desc' => '预警金额'],
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
        return static::commonConfigSchema(self::ENDPOINT, '默认 POST api/webhook/users/warn/funds，JSON body');
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->resolveMerchantId($context);

        if ($merchantId === '') {
            return $this->localizedFail($this->notBoundTip($context));
        }

        $warnFunds = $this->amountArg($context);

        if ($warnFunds === null) {
            return $this->localizedFail($this->t($context, 'warn.usage'));
        }

        $data = $this->callUpstream($context, self::ENDPOINT, [
            'user_id' => $merchantId,
            'warn_funds' => $warnFunds,
        ], 'POST');

        if (isset($data['_error'])) {
            return $this->localizedFail($this->t($context, 'warn.failed') . $data['_error']);
        }

        return $this->localizedReply(
            $this->t($context, 'warn.success', ['amount' => $warnFunds]),
            $data
        );
    }
}
