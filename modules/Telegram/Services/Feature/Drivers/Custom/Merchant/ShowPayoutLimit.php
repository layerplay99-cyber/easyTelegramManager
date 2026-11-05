<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * /chaxiane —— 查询自动代付限额
 *
 * 上游：POST /api/webhook/user/limit/show  With Token，JSON body
 *   {"user_id": "<商户ID>"}
 *
 * 返回 limit_money；上游返回 0 / 空 / 没有该字段时按默认限额展示
 * （默认 50000000，后台可改），因为「没设置」在上游等价于系统默认值。
 */
class ShowPayoutLimit extends AbstractMerchantFeature implements EndpointProvider
{
    private const ENDPOINT = 'merchant.limit.show';

    private const DEFAULT_TEMPLATE = '当前商户代付订单二次确认限制金额为：{{limit_money}}';

    private const ZERO_TEMPLATE = '当前商户代付订单二次确认限制金额为：{{default_limit}}';

    private const DEFAULT_LIMIT = '50000000';

    public static function key(): string
    {
        return 'custom.show_payout_limit';
    }

    public static function label(): string
    {
        return '查询自动代付限额';
    }

    public static function featureName(): string
    {
        return '查询自动代付限额';
    }

    public static function featureDescription(): string
    {
        return '查询代付订单二次确认的金额门槛（/chaxiane）';
    }

    public static function endpoints(): array
    {
        return [
            'merchant.limit.show' => [
                'name' => '查询自动代付限额',
                'method' => 'POST',
                'path_template' => 'api/webhook/user/limit/show',
                'params_schema' => [
                    ['name' => 'user_id', 'required' => true, 'desc' => '商户ID', 'map_to' => 'merchant_id'],
                ],
                'response_schema' => [
                    'limit_money' => '二次确认限制金额',
                ],
                'remark' => 'JSON body；With Token',
            ],
        ];
    }

    public static function commands(): array
    {
        return [
            [
                'command' => 'chaxiane',
                'usage' => '/chaxiane',
                'description' => '查询当前代付订单二次确认限额',
                'params' => [],
                'reply_template' => self::DEFAULT_TEMPLATE,
            ],
        ];
    }

    public static function defaultConfig(): array
    {
        return static::commonDefaultConfig(self::ENDPOINT, [
            'reply_template' => self::DEFAULT_TEMPLATE,
            'zero_reply_template' => self::ZERO_TEMPLATE,
            'default_limit' => self::DEFAULT_LIMIT,
        ]);
    }

    public static function configSchema(): array
    {
        return array_merge(
            static::commonConfigSchema(self::ENDPOINT, '默认 POST api/webhook/user/limit/show，JSON body'),
            [
                [
                    'key' => 'reply_template',
                    'label' => '回复模板（有设置限额）',
                    'type' => 'template',
                    'required' => false,
                    'default' => self::DEFAULT_TEMPLATE,
                    'hint' => '可用变量：{{limit_money}} {{default_limit}} {{merchant_id}} {{user_id}} {{raw_json}}',
                ],
                [
                    'key' => 'zero_reply_template',
                    'label' => '回复模板（未设置限额）',
                    'type' => 'template',
                    'required' => false,
                    'default' => self::ZERO_TEMPLATE,
                    'hint' => '上游返回 0 或空时使用；可用变量同上',
                ],
                [
                    'key' => 'default_limit',
                    'label' => '未设置时的默认限额',
                    'type' => 'number',
                    'required' => false,
                    'default' => self::DEFAULT_LIMIT,
                    'hint' => '上游返回 0 / 空时按此值展示（系统默认 50000000）',
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

        $data = $this->callUpstream($context, self::ENDPOINT, [
            'user_id' => $merchantId,
        ], 'POST');

        if (isset($data['_error'])) {
            return $this->fail('查询代付限额失败：' . $data['_error']);
        }

        // 兼容上游把返回值包在 data 里的情况
        $limit = data_get($data, 'limit_money') ?? data_get($data, 'data.limit_money');
        $hasLimit = is_scalar($limit) && is_numeric((string) $limit) && (float) $limit > 0;

        $defaultLimit = (string) $context->config('default_limit', self::DEFAULT_LIMIT);
        if (trim($defaultLimit) === '') {
            $defaultLimit = self::DEFAULT_LIMIT;
        }

        $vars = $this->responseVars($data, [
            'merchant_id' => $merchantId,
            'user_id' => $merchantId,
            'limit_money' => $hasLimit ? (string) $limit : $defaultLimit,
            'default_limit' => $defaultLimit,
        ]);

        $text = $hasLimit
            ? $this->renderText($context, 'reply_template', self::DEFAULT_TEMPLATE, $vars)
            : $this->renderText($context, 'zero_reply_template', self::ZERO_TEMPLATE, $vars);

        return $this->reply($text, $data);
    }
}
