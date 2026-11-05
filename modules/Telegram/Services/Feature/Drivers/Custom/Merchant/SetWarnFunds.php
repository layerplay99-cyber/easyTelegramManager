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
 * NULL（异步生效），群里要看到的是本次实际提交的值。
 */
class SetWarnFunds extends AbstractMerchantFeature implements EndpointProvider
{
    private const ENDPOINT = 'merchant.warn.funds';

    private const DEFAULT_TEMPLATE = '设置成功，当前余额预警线为：{{warn_funds}}';

    private const TEMPLATE_HINT = '可用变量：{{warn_funds}}（本次设置的金额）{{merchant_id}} {{user_id}} '
        . '{{data.available_funds}} {{data.locked_funds}} {{data.warn_funds}} '
        . '{{currency.code}} {{currency.symbol}} {{raw_json}}';

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
        return '设置当前群绑定商户的余额预警线（/yujing <金额>）';
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
                'description' => '设置余额不足提醒的预警金额',
                'params' => [
                    ['name' => 'warn_funds', 'required' => true, 'desc' => '预警金额', 'pattern' => '^\d+(\.\d{1,2})?$'],
                ],
                'reply_template' => self::DEFAULT_TEMPLATE,
            ],
        ];
    }

    public static function defaultConfig(): array
    {
        return static::commonDefaultConfig(self::ENDPOINT, [
            'reply_template' => self::DEFAULT_TEMPLATE,
        ]);
    }

    public static function configSchema(): array
    {
        return array_merge(
            static::commonConfigSchema(self::ENDPOINT, '默认 POST api/webhook/users/warn/funds，JSON body'),
            [
                [
                    'key' => 'reply_template',
                    'label' => '回复模板',
                    'type' => 'template',
                    'required' => false,
                    'default' => self::DEFAULT_TEMPLATE,
                    'hint' => self::TEMPLATE_HINT,
                ],
            ]
        );
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->resolveMerchantId($context);

        if ($merchantId === '') {
            return $this->fail($this->notBoundTip($context));
        }

        $warnFunds = $this->amountArg($context);

        if ($warnFunds === null) {
            return $this->fail('金额不正确，用法：/yujing <金额>');
        }

        $data = $this->callUpstream($context, self::ENDPOINT, [
            'user_id' => $merchantId,
            'warn_funds' => $warnFunds,
        ], 'POST');

        if (isset($data['_error'])) {
            return $this->fail('设置余额预警失败：' . $data['_error']);
        }

        $vars = $this->responseVars($data, [
            'merchant_id' => $merchantId,
            'user_id' => $merchantId,
            'warn_funds' => $warnFunds,
        ]);

        return $this->reply(
            $this->renderText($context, 'reply_template', self::DEFAULT_TEMPLATE, $vars),
            $data
        );
    }
}
