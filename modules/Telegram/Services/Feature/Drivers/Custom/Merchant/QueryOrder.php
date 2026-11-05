<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * /cx <订单ID> —— 查询订单（代收 / 代付）
 *
 * 上游：GET /api/webhook/users/{userId}/trades/{id}  With Token
 *   userId = 本群绑定的商户号（merchant_routes / group_configs.mid）
 *   id     = 命令参数订单ID
 *
 * 代收（Receive money）与代付（Send money）、以及 Successful / Processing /
 * Pending 三种状态返回的参数个数都不一样，所以：
 *   · 回复模板在后台可配，默认模板里列全所有字段；
 *   · 没返回的字段渲染成 '-'，开启「隐藏空值行」后整行自动删掉；
 *   · 可以分别为代收 / 代付配专用模板（留空则用通用模板）；
 *   · 另外提供 type_cn（代付/代收）、status_cn（成功/处理中/待处理）两个中文变量。
 */
class QueryOrder extends AbstractMerchantFeature implements EndpointProvider
{
    private const ENDPOINT = 'merchant.trade.query';

    private const DEFAULT_TEMPLATE = "📋 订单信息\n"
        . "类型：{{type_cn}}\n"
        . "订单号：{{reference_id}}\n"
        . "商户订单号：{{transaction_id}}\n"
        . "创建时间：{{created_time}}\n"
        . "金额：{{amount}}\n"
        . "手续费：{{fees}}\n"
        . "合计：{{total}}\n"
        . "余额：{{balance}}\n"
        . "币种：{{currency_code}}\n"
        . "收款人姓名：{{payee_name}}\n"
        . "收款人账号：{{payee_account}}\n"
        . "状态：{{status_cn}}\n"
        . "付款时间：{{paid_time}}\n"
        . "提供商ID：{{provider_id}}\n"
        . "结算金额：{{settlement_amount}}\n"
        . "结算时间：{{settled_time}}";

    private const TEMPLATE_HINT = '可用变量：{{type}} {{type_cn}} {{reference_id}} {{transaction_id}} '
        . '{{created_time}} {{amount}} {{fees}} {{total}} {{balance}} {{currency_code}} '
        . '{{payee_name}} {{payee_account}} {{status}} {{status_cn}} {{paid_time}} '
        . '{{provider_id}} {{settlement_amount}} {{settled_time}} {{merchant_id}} {{order_id}} {{raw_json}}；'
        . '上游若包了 data 层也可写 {{data.amount}}；缺失字段显示 -';

    public static function key(): string
    {
        return 'custom.query_order';
    }

    public static function label(): string
    {
        return '查询订单';
    }

    public static function featureName(): string
    {
        return '查询订单';
    }

    public static function featureDescription(): string
    {
        return '按订单ID查询代收/代付订单详情，回复模板可在后台配置（/cx <订单ID>）';
    }

    /**
     * 本功能用到的上游接口规范
     */
    public static function endpoints(): array
    {
        return [
            'merchant.trade.query' => [
                'name' => '查询订单',
                'method' => 'GET',
                'path_template' => 'api/webhook/users/{userId}/trades/{id}',
                'params_schema' => [
                    ['name' => 'userId', 'required' => true, 'desc' => '商户ID（路径参数）', 'map_to' => 'merchant_id'],
                    ['name' => 'id', 'required' => true, 'desc' => '订单ID（路径参数）', 'map_to' => 'order_id'],
                ],
                'response_schema' => [
                    'type' => 'Send money=代付 / Receive money=代收',
                    'reference_id' => '订单ID',
                    'transaction_id' => '商户订单ID',
                    'created_time' => '创建时间',
                    'amount' => '金额',
                    'fees' => '手续费',
                    'total' => '合计',
                    'balance' => '余额',
                    'currency_code' => '币种',
                    'payee_name' => '收款人姓名',
                    'payee_account' => '收款人账号',
                    'status' => 'Successful=成功 / Processing=处理中 / Pending=待处理',
                    'paid_time' => '付款时间',
                    'provider_id' => '提供商ID',
                    'settlement_amount' => '结算金额',
                    'settled_time' => '结算时间',
                ],
                'remark' => '代收/代付、不同状态返回的字段个数不同，缺失字段按空值处理',
            ],
        ];
    }

    public static function commands(): array
    {
        return [
            [
                'command' => 'cx',
                'usage' => '/cx <订单ID>',
                'description' => '查询代收/代付订单详情',
                'params' => [
                    ['name' => 'order_id', 'required' => true, 'desc' => '订单ID', 'pattern' => '^\S+$'],
                ],
                'reply_template' => self::DEFAULT_TEMPLATE,
            ],
        ];
    }

    public static function defaultConfig(): array
    {
        return static::commonDefaultConfig(self::ENDPOINT, [
            'reply_template' => self::DEFAULT_TEMPLATE,
            'reply_template_receive' => '',
            'reply_template_send' => '',
            'hide_empty_lines' => true,
        ]);
    }

    public static function configSchema(): array
    {
        return array_merge(
            static::commonConfigSchema(self::ENDPOINT, '默认 GET api/webhook/users/{userId}/trades/{id}；可在「上游接口配置」里覆盖'),
            [
                [
                    'key' => 'reply_template',
                    'label' => '回复模板（通用）',
                    'type' => 'template',
                    'required' => false,
                    'default' => self::DEFAULT_TEMPLATE,
                    'hint' => self::TEMPLATE_HINT,
                ],
                [
                    'key' => 'reply_template_receive',
                    'label' => '回复模板（代收专用）',
                    'type' => 'template',
                    'required' => false,
                    'hint' => 'Receive money 时使用；留空则用通用模板。变量同通用模板',
                ],
                [
                    'key' => 'reply_template_send',
                    'label' => '回复模板（代付专用）',
                    'type' => 'template',
                    'required' => false,
                    'hint' => 'Send money 时使用；留空则用通用模板。变量同通用模板',
                ],
                [
                    'key' => 'hide_empty_lines',
                    'label' => '隐藏空值行',
                    'type' => 'switch',
                    'required' => false,
                    'default' => true,
                    'hint' => '把「字段：-」这种上游没返回的整行删掉，代收/代付字段不一致时回复更干净',
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

        $orderId = trim((string) ($context->arg(0) ?? ''));

        if ($orderId === '') {
            return $this->fail('请提供订单ID，用法：/cx <订单ID>');
        }

        // GET 的路径占位由 callEndpoint 填充，两个参数不会再重复拼到 query 上
        $data = $this->callUpstream($context, self::ENDPOINT, [
            'userId' => $merchantId,
            'id' => $orderId,
        ], 'GET');

        if (isset($data['_error'])) {
            return $this->fail('查询订单失败：' . $data['_error']);
        }

        $vars = $this->responseVars($data, [
            'merchant_id' => $merchantId,
            'order_id' => $orderId,
        ]);

        $rawType = data_get($vars, 'type', '');
        $rawStatus = data_get($vars, 'status', '');
        $type = strtolower(trim(is_scalar($rawType) ? (string) $rawType : ''));
        $status = strtolower(trim(is_scalar($rawStatus) ? (string) $rawStatus : ''));

        $vars['type_cn'] = match ($type) {
            'send money' => '代付',
            'receive money' => '代收',
            default => (string) (is_scalar($rawType) ? $rawType : ''),
        };

        $vars['status_cn'] = match ($status) {
            'successful' => '成功',
            'processing' => '处理中',
            'pending' => '待处理',
            default => (string) (is_scalar($rawStatus) ? $rawStatus : ''),
        };

        // 代收 / 代付可各配一份模板，没配就退回通用模板
        $specificKey = match ($type) {
            'receive money' => 'reply_template_receive',
            'send money' => 'reply_template_send',
            default => '',
        };

        $specific = $specificKey !== '' ? trim((string) $context->config($specificKey, '')) : '';

        $text = $specific !== ''
            ? $this->render($specific, $vars, $context)
            : $this->renderText($context, 'reply_template', self::DEFAULT_TEMPLATE, $vars);

        if ((bool) $context->config('hide_empty_lines', true)) {
            $text = $this->stripEmptyLines($text);
        }

        if (trim($text) === '') {
            $text = '未查询到订单 ' . $orderId . ' 的信息';
        }

        return $this->reply($text, $data);
    }
}
