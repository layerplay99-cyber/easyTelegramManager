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
 * 金额传 0 表示取消二次确认，走单独一条回复文案（后台可配）。
 */
class SetPayoutLimit extends AbstractMerchantFeature implements EndpointProvider
{
    private const ENDPOINT = 'merchant.limit.set';

    private const DEFAULT_TEMPLATE = '设置成功，当前商户代付订单金额大于：{{limit_money}}时需要二次确认';

    private const ZERO_TEMPLATE = '设置成功，当前商户代付取消二次确认';

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
        return '设置代付订单二次确认的金额门槛，0 为取消二次确认（/xiane <金额>）';
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
                'description' => '设置代付订单二次确认限额，0 为取消',
                'params' => [
                    ['name' => 'limit_money', 'required' => true, 'desc' => '限额金额', 'pattern' => '^\d+(\.\d{1,2})?$'],
                ],
                'reply_template' => self::DEFAULT_TEMPLATE,
            ],
        ];
    }

    public static function defaultConfig(): array
    {
        return static::commonDefaultConfig(self::ENDPOINT, [
            'reply_template' => self::DEFAULT_TEMPLATE,
            'zero_reply_template' => self::ZERO_TEMPLATE,
        ]);
    }

    public static function configSchema(): array
    {
        return array_merge(
            static::commonConfigSchema(self::ENDPOINT, '默认 POST api/webhook/user/limit/set，JSON body'),
            [
                [
                    'key' => 'reply_template',
                    'label' => '回复模板（设置了限额）',
                    'type' => 'template',
                    'required' => false,
                    'default' => self::DEFAULT_TEMPLATE,
                    'hint' => '可用变量：{{limit_money}} {{merchant_id}} {{user_id}} {{raw_json}}',
                ],
                [
                    'key' => 'zero_reply_template',
                    'label' => '回复模板（取消二次确认）',
                    'type' => 'template',
                    'required' => false,
                    'default' => self::ZERO_TEMPLATE,
                    'hint' => 'limit_money 为 0 时使用；可用变量同上',
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

        $limitMoney = $this->amountArg($context);

        if ($limitMoney === null) {
            return $this->fail('金额不正确，用法：/xiane <金额>（0 表示取消二次确认）');
        }

        $data = $this->callUpstream($context, self::ENDPOINT, [
            'user_id' => $merchantId,
            'limit_money' => $limitMoney,
        ], 'POST');

        if (isset($data['_error'])) {
            return $this->fail('设置代付限额失败：' . $data['_error']);
        }

        $vars = $this->responseVars($data, [
            'merchant_id' => $merchantId,
            'user_id' => $merchantId,
            'limit_money' => $limitMoney,
        ]);

        // 0 = 取消二次确认，文案与「设置了某个金额」完全不同
        $text = (float) $limitMoney === 0.0
            ? $this->renderText($context, 'zero_reply_template', self::ZERO_TEMPLATE, $vars)
            : $this->renderText($context, 'reply_template', self::DEFAULT_TEMPLATE, $vars);

        return $this->reply($text, $data);
    }
}
