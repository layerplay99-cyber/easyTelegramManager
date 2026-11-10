<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * /cx <订单ID> —— 查询订单（代收 / 代付）
 *
 * 上游：GET /api/webhook/users/{merchant_id}/trades/{id}  With Token
 *   {merchant_id} = 本群绑定的商户号（merchant_routes / group_configs.mid），
 *                   由后台 path 模板占位，与其它商户接口统一命名
 *   {id}          = 命令参数订单ID，由本类 rawurlencode 后追加到路径末尾，
 *                   不写进 path 模板、不进 query
 *   最终 URL：/api/webhook/users/1/trades/202605487749548
 *
 * 上游返回什么字段就显示什么字段，不写死字段清单、也不在后台配模板：
 *   · FIELD_ORDER 只决定已知字段的排列顺序，没列进去的字段追加在后面；
 *   · 字段名去 lang/{locale}/merchant.php 找译文，找不到的用原始字段名；
 *   · type / status 的英文枚举值翻译成中文/英文/越南语/泰语；
 *   · 要过滤字段就改 HIDDEN_FIELDS（人工在代码里处理）。
 *
 * 【示例看点 · 路线 A】（docs/feature-dev.md §2）
 *   · 动态资源段（订单号）用 callEndpoint 的 $pathSuffix 追加：rawurlencode 后拼到
 *     路径末尾，不写进 path 模板、不进 query —— 后台给每个上游配路径时不用带 {id}
 *   · 上游返回什么显示什么：FIELD_ORDER 只定顺序，未收录字段按原顺序追加，天然兼容
 *   · 继承 AbstractMerchantFeature，复用「解析商户号 + 调上游 + 多语回复」三件套；
 *     configSchema / defaultConfig 用 commonConfigSchema() 一行生成
 *   · 插件版对照：resources/plugin-examples/merchant-demo/
 */
class QueryOrder extends AbstractMerchantFeature implements EndpointProvider
{
    private static mixed $ENDPOINT = 'merchant.trade.query';

    /** 已知字段的显示顺序（不影响内容，上游多返回的字段会追加在后面） */
    private const FIELD_ORDER = [
        'type',
        'reference_id',
        'transaction_id',
        'created_time',
        'amount',
        'fees',
        'total',
        'balance',
        'currency_code',
        'payee_name',
        'payee_account',
        'status',
        'paid_time',
        'provider_id',
        'settlement_amount',
        'settled_time',
    ];

    /** 要过滤掉哪些字段，人工在这里改 */
    private const HIDDEN_FIELDS = ['_error', '_raw', 'raw_json'];

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
        return '按订单ID查询代收/代付订单，上游返回什么字段就显示什么，字段名按群语种翻译（/cx <订单ID>）';
    }

    public static function endpoints(): array
    {
        return [
            'merchant.trade.query' => [
                'name' => '查询订单',
                'method' => 'GET',
                // 只写到 trades：末尾的 /{订单ID} 由 QueryOrder 追加，
                // 后台给每个上游配路径时不用（也不该）带 {id}
                'path_template' => 'api/webhook/users/{merchant_id}/trades',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true, 'desc' => '商户ID（path 占位）'],
                    ['name' => 'id', 'required' => true, 'desc' => '订单ID（代码追加到路径末尾，不写进 path 模板）'],
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
                'remark' => 'path 只写到 trades，末尾 /{订单ID} 由 QueryOrder 追加；代收/代付、不同状态返回字段个数不同，按实际返回逐行显示',
            ],
        ];
    }

    public static function commands(): array
    {
        return [
            [
                'command' => 'cx',
                'usage' => '/cx <订单ID>',
                'description' => '查询代收/代付订单详情（按群语种回复）',
                // 不声明 required/pattern：那两种校验的错误文案由通用管线写死中文。
                // 参数合法性统一在 handle() 里判，保证群内回复全都走语言包。
                'params' => [
                    ['name' => 'order_id', 'desc' => '订单ID'],
                ],
            ],
        ];
    }

    public static function defaultConfig(): array
    {
        return static::commonDefaultConfig(self::$ENDPOINT);
    }

    public static function configSchema(): array
    {
        return static::commonConfigSchema(
            self::$ENDPOINT,
            '默认 GET api/webhook/users/{merchant_id}/trades，订单号由代码追加；「上游接口配置」里若覆盖路径，末尾不要写 /{id}。回复字段名按群语种自动翻译'
        );
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->resolveMerchantId($context);

        if ($merchantId === '') {
            return $this->localizedFail($this->notBoundTip($context));
        }

        $orderId = trim((string) ($context->arg(0) ?? ''));

        if ($orderId === '') {
            return $this->localizedFail($this->t($context, 'order.usage'));
        }

        $data = $this->callUpstream(
            $context,
            self::$ENDPOINT,
            ['merchant_id' => $merchantId],
            'GET',
            rawurlencode($orderId)
        );

        if( $data['data'] === [] ){
            return $this->localizedReply($this->t($context, 'order.not_found', ['id' => $orderId]), $data);
        }

        $lines = [];
        $separator = $this->fieldSeparator($context);

        foreach ($this->orderedFields($this->businessRow($data)) as $field => $value) {
            $lines[] = $this->fieldLabel($context, (string) $field)
                . $separator
                . $this->formatValue($context, (string) $field, $value);
        }

        if ($lines === []) {
            return $this->localizedReply($this->t($context, 'order.not_found', ['id' => $orderId]), $data);
        }

        return $this->localizedReply(
            $this->t($context, 'order.title') . "\n" . implode("\n", $lines),
            $data
        );
    }

    /**
     * 剥掉上游常见的 { code, message, data: {...} } 包装，最多两层
     */
    protected function businessRow(array $response): array
    {
        $row = $response;

        for ($i = 0; $i < 2; $i++) {
            $inner = data_get($row, 'data');

            if (! is_array($inner) || $inner === []) {
                break;
            }

            $row = $inner;
        }

        return $row;
    }

    /**
     * 已知字段按 FIELD_ORDER 排，其余（上游新增/未收录的字段）按原顺序追加
     */
    protected function orderedFields(array $row): array
    {
        $ordered = [];

        foreach (self::FIELD_ORDER as $field) {
            if (array_key_exists($field, $row)) {
                $ordered[$field] = $row[$field];
            }
        }

        foreach ($row as $field => $value) {
            $field = (string) $field;

            if (in_array($field, self::HIDDEN_FIELDS, true) || array_key_exists($field, $ordered)) {
                continue;
            }

            $ordered[$field] = $value;
        }

        return $ordered;
    }

    /**
     * 值格式化：空值给 '-'，数组转 JSON，type/status 枚举按群语种翻译
     */
    protected function formatValue(FeatureContext $context, string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_bool($value)) {
            return $this->t($context, $value ? 'common.yes' : 'common.no');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '-';
        }

        if (! is_scalar($value)) {
            return '-';
        }

        $text = (string) $value;

        return match ($field) {
            'type' => $this->valueLabel($context, 'type_values', $text),
            'status' => $this->valueLabel($context, 'status_values', $text),
            default => $text,
        };
    }
}
