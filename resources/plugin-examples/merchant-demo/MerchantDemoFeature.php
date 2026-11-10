<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantDemo;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;

/**
 * 商户三件套示例插件（路线 B，docs/feature-dev.md §3）
 *
 * 与内部版 Drivers/Custom/Merchant 的 BindMerchant / GetMerchantBalance / QueryOrder
 * 功能同构，写法刻意自包含，方便对比「内部功能 → 插件」要改什么：
 *
 *   | 差异点         | 内部版                             | 本插件 |
 *   | -------------- | ---------------------------------- | ------ |
 *   | 命名空间       | Modules\Telegram\...\Custom\Merchant | Modules\Telegram\Plugins\MerchantDemo（slug 转驼峰，审核强制） |
 *   | 三个功能三处代码 | 三个类，商户号走 merchant_routes 服务 | 一个类三个命令，商户号用 putData 自存自取 |
 *   | 多语言         | LocalizedReply trait + 语言包       | 自带中文文案（插件也能 use 核心 trait，见 README） |
 *   | 接口 code      | merchant.*（内置注册）             | demo.*（插件必须用自己的前缀，别覆盖内置接口） |
 *   | 进系统方式     | 随代码发布 + sync-features          | zip 上传 → 脚本/AI 审核 → 自动同步进功能列表 |
 *
 * 一个类带多个命令：commands() 声明三条，handle() 里按 $ctx->command 分派。
 * 上游是谁由「机器人 → 可绑定上游」决定，代码里不写死；没配上游时命令会明确报错。
 */
class MerchantDemoFeature extends BaseCustomFeature implements EndpointProvider
{
    public static function key(): string
    {
        return 'plugin.merchant_demo';
    }

    /**
     * 执行器下拉里的显示名（FeatureDriver 契约要求）
     */
    public static function label(): string
    {
        return '商户示例（插件）';
    }

    public static function featureName(): string
    {
        return '商户示例（插件）';
    }

    public static function featureDescription(): string
    {
        return '路线 B 教学插件：/bmd 绑定商户号、/ymd 查余额、/cxm 查订单（与内部版功能同构）';
    }

    public static function group(): string
    {
        return '自定义';
    }

    /**
     * 上游接口声明：code 全局唯一，插件用自己的前缀（demo.*）。
     *
     * path_template 换成你上游的真实路径；{merchant_id} 是路径占位，
     * 订单号不写进模板（由 handle() 用 $pathSuffix 追加，见 order()）。
     */
    public static function endpoints(): array
    {
        return [
            'demo.check' => [
                'name' => '示例：校验商户是否存在',
                'method' => 'GET',
                'path_template' => 'api/merchant/check',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true, 'desc' => '商户号'],
                ],
                'response_schema' => [
                    'data' => ['id' => '上游返回的商户号（与输入一致才算存在）'],
                ],
            ],
            'demo.balance' => [
                'name' => '示例：查询商户余额',
                'method' => 'GET',
                'path_template' => 'api/merchant/balance',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true, 'desc' => '商户号'],
                ],
                'response_schema' => [
                    'balance' => '余额',
                    'currency' => '币种',
                ],
            ],
            'demo.order' => [
                'name' => '示例：查询订单',
                'method' => 'GET',
                'path_template' => 'api/webhook/users/{merchant_id}/trades',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true, 'desc' => '商户号（path 占位）'],
                    ['name' => 'id', 'required' => true, 'desc' => '订单号（代码追加到路径末尾）'],
                ],
                'response_schema' => [
                    'type' => 'Send money=代付 / Receive money=代收',
                    'reference_id' => '订单ID',
                    'amount' => '金额',
                    'currency_code' => '币种',
                    'status' => 'Successful=成功 / Processing=处理中 / Pending=待处理',
                    'paid_time' => '付款时间',
                ],
            ],
        ];
    }

    /**
     * 三条斜杠命令：绑定 / 余额 / 订单
     */
    public static function commands(): array
    {
        return [
            [
                'command' => 'bmd',
                'usage' => '/bmd 商户号',
                'description' => '绑定/查看本群商户号（插件示例）',
                'params' => [
                    ['name' => 'merchant_id', 'required' => false, 'hint' => '商户号，留空表示查看'],
                ],
            ],
            [
                'command' => 'ymd',
                'usage' => '/ymd',
                'description' => '查询本群绑定商户的余额（插件示例）',
                'params' => [],
            ],
            [
                'command' => 'cxm',
                'usage' => '/cxm 订单号',
                'description' => '按订单号查询订单（插件示例）',
                'params' => [
                    ['name' => 'order_id', 'required' => true, 'hint' => '订单号'],
                ],
            ],
        ];
    }

    public static function defaultConfig(): array
    {
        return [
            // 数据按群持久化（/bmd 写的 merchant_id 就存在这个作用域下）
            'scope_type' => 'group',
            'not_bound_tip' => '本群还没绑定商户号，先发 /bmd 商户号',
            'bound_reply' => '已绑定商户号：{{merchant_id}}',
        ];
    }

    /**
     * 后台配置表单（前端自动渲染）
     */
    public static function configSchema(): array
    {
        return [
            [
                'key' => 'not_bound_tip',
                'label' => '未绑定提示',
                'type' => 'text',
                'required' => false,
                'default' => '本群还没绑定商户号，先发 /bmd 商户号',
            ],
            [
                'key' => 'bound_reply',
                'label' => '绑定成功回复',
                'type' => 'template',
                'required' => false,
                'default' => '已绑定商户号：{{merchant_id}}',
                'hint' => '可用变量 {{merchant_id}}',
            ],
        ];
    }

    /**
     * 命令分派：一个类多个命令时在 handle() 入口按 $ctx->command 路由
     */
    public function handle(FeatureContext $context): FeatureResult
    {
        $command = ltrim($context->command, '/');

        return match ($command) {
            'bmd' => $this->bind($context),
            'ymd' => $this->balance($context),
            'cxm' => $this->order($context),
            default => $this->fail('未知命令：' . $command),
        };
    }

    /**
     * /bmd 商户号 —— 与内部版 BindMerchant 同逻辑（自包含版）
     */
    protected function bind(FeatureContext $context): FeatureResult
    {
        // 读实参用 arg()，不用 value()：已存数据不能顶掉「本次输入」
        $merchantId = trim((string) ($context->arg(0) ?? ''));

        // 不带参数 = 查看当前绑定（只读本地，不打上游）
        if ($merchantId === '') {
            $bound = trim((string) ($context->value('merchant_id', '')));

            return $bound === ''
                ? $this->fail((string) $context->config('not_bound_tip', '先发 /bmd 商户号'))
                : $this->reply($this->render(
                    (string) $context->config('bound_reply', '已绑定：{{merchant_id}}'),
                    ['merchant_id' => $bound],
                    $context
                ));
        }

        // 绑定前先向上游确认商户号存在：返回的 id 必须与输入一致
        $check = $this->callEndpoint('demo.check', ['merchant_id' => $merchantId], $context);

        if (isset($check['_error'])) {
            return $this->fail('校验商户失败：' . $check['_error']);
        }

        $returned = data_get($check, 'data.id', data_get($check, 'id'));

        if ($returned === null || (string) $returned !== $merchantId) {
            return $this->fail('商户号不存在：' . $merchantId);
        }

        // 按作用域持久化；之后 /ymd /cxm 用 value('merchant_id') 直接取到
        $this->putData($context, ['merchant_id' => $merchantId]);

        return $this->reply($this->render(
            (string) $context->config('bound_reply', '已绑定：{{merchant_id}}'),
            ['merchant_id' => $merchantId],
            $context
        ));
    }

    /**
     * /ymd —— 与内部版 GetMerchantBalance 同逻辑
     */
    protected function balance(FeatureContext $context): FeatureResult
    {
        $merchantId = trim((string) $context->value('merchant_id', ''));

        if ($merchantId === '') {
            return $this->fail((string) $context->config('not_bound_tip', '先发 /bmd 商户号'));
        }

        $data = $this->callEndpoint('demo.balance', ['merchant_id' => $merchantId], $context);

        if (isset($data['_error'])) {
            return $this->fail('查询失败：' . $data['_error']);
        }

        // 只读平台标准字段（endpoints() 里 response_schema 声明的）
        $balance = data_get($data, 'data.balance', data_get($data, 'balance'));
        $currency = data_get($data, 'data.currency', data_get($data, 'currency', ''));

        if (! is_scalar($balance) || trim((string) $balance) === '') {
            return $this->fail('上游未返回 data.balance：请确认该上游已配适配器，把响应归一成标准结构');
        }

        return $this->reply("余额：{$balance} {$currency}");
    }

    /**
     * /cxm 订单号 —— 与内部版 QueryOrder 同逻辑
     */
    protected function order(FeatureContext $context): FeatureResult
    {
        $merchantId = trim((string) $context->value('merchant_id', ''));

        if ($merchantId === '') {
            return $this->fail((string) $context->config('not_bound_tip', '先发 /bmd 商户号'));
        }

        $orderId = trim((string) ($context->arg(0) ?? ''));

        if ($orderId === '') {
            return $this->fail('用法：/cxm 订单号');
        }

        // 订单号是动态资源段：rawurlencode 后追加到路径末尾，
        // 不写进 path 模板、不进 query（与内部版 QueryOrder 的 $pathSuffix 一致）
        $data = $this->callEndpoint(
            'demo.order',
            ['merchant_id' => $merchantId],
            $context,
            'GET',
            rawurlencode($orderId)
        );

        if (isset($data['_error'])) {
            return $this->fail('查询失败：' . $data['_error']);
        }

        $row = $this->businessRow($data);

        if ($row === [] ) {
            return $this->fail('订单不存在：' . $orderId);
        }

        // 上游返回什么显示什么；已知字段排前面，其余追加
        $lines = [];
        $known = ['type', 'reference_id', 'transaction_id', 'amount', 'currency_code', 'status', 'paid_time'];

        foreach ($known as $field) {
            if (array_key_exists($field, $row)) {
                $lines[] = $field . '：' . $this->display($row[$field]);
            }
        }

        foreach ($row as $field => $value) {
            if (! in_array($field, $known, true) && ! str_starts_with((string) $field, '_')) {
                $lines[] = $field . '：' . $this->display($value);
            }
        }

        if ($lines === []) {
            return $this->fail('订单不存在：' . $orderId);
        }

        return $this->reply("订单 {$orderId}\n" . implode("\n", $lines));
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
     * 值显示：空值给 '-'，数组转 JSON，其余原样
     */
    protected function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '-';
        }

        return is_scalar($value) ? (string) $value : '-';
    }
}
