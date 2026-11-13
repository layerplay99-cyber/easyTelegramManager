<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\MerchantRouteService;

/**
 * 商户支付插件 · 功能基类
 *
 * 一条命令 = 一个功能类 = 功能列表里一行，可单独启用 / 停用 / 绑定 / 配置。
 * 本基类只放三类共享内容，不含任何命令逻辑：
 *
 *   1) 平台接口规范（endpoints()）——全插件 11 个 pay.* 接口只声明一次；
 *      每个具体功能用 upstreamEndpoints() 精确声明自己实际调用的那几个。
 *   2) 群 ↔ 商户号绑定（merchantId()）——拆成多个功能后 feature_data 按
 *      feature_id 隔离，/bd 存的数据别的命令读不到，所以统一以
 *      merchant_routes 路由表为准，stored / 群默认商户号只作历史兜底。
 *   3) 金额、响应表格、审批人解析等工具方法。
 *
 * 抽象基类不会被 CustomFeatureRegistry 当成功能（isAbstract 过滤），
 * 也不会出现在功能列表里；13 个具体子类才是可配置的功能。
 */
abstract class MerchantPayFeature extends BaseCustomFeature implements EndpointProvider
{
    public static function group(): string
    {
        return '三方支付';
    }

    /**
     * 上游接口一律通过 callEndpoint('pay.xxx') 走平台适配器，响应归一成标准结构；
     * 所有 data 的具体映射由开发人员在 response_schema 里按上游文档补。
     */
    public static function endpoints(): array
    {
        return [
            'pay.check' => [
                'name' => '校验商户是否存在',
                'method' => 'GET',
                'path_template' => 'api/merchant/check',
                'params_schema' => [['name' => 'merchant_id', 'required' => true]],
                'response_schema' => ['data' => ['id' => '商户号（与输入一致才算存在）']],
            ],
            'pay.balance' => [
                'name' => '查询商户余额',
                'method' => 'GET',
                'path_template' => 'api/merchant/balance',
                'params_schema' => [['name' => 'merchant_id', 'required' => true]],
                'response_schema' => ['balance' => '余额', 'currency' => '币种'],
            ],
            'pay.limit.query' => [
                'name' => '查询代付审批限额',
                'method' => 'GET',
                'path_template' => 'api/merchant/payout/limit',
                'params_schema' => [['name' => 'merchant_id', 'required' => true]],
                'response_schema' => ['min' => '单笔下限', 'max' => '单笔上限', 'currency' => '币种'],
            ],
            'pay.limit.set' => [
                'name' => '设置代付审批限额',
                'method' => 'POST',
                'path_template' => 'api/merchant/payout/limit',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true],
                    ['name' => 'min', 'required' => true],
                    ['name' => 'max', 'required' => true],
                ],
                'response_schema' => ['success' => 'true=设置成功'],
            ],
            'pay.warn.set' => [
                'name' => '设置余额不足提醒阈值',
                'method' => 'POST',
                'path_template' => 'api/merchant/warn',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true],
                    ['name' => 'threshold', 'required' => true],
                ],
                'response_schema' => ['success' => 'true=设置成功'],
            ],
            'pay.operator.set' => [
                'name' => '设置审批人员',
                'method' => 'POST',
                'path_template' => 'api/merchant/approvers',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true],
                    ['name' => 'operators', 'required' => true, 'desc' => '审批人列表'],
                ],
                'response_schema' => ['success' => 'true=设置成功'],
            ],
            'pay.order' => [
                'name' => '查询订单',
                'method' => 'GET',
                'path_template' => 'api/merchant/orders/{merchant_id}',
                'params_schema' => [['name' => 'merchant_id', 'required' => true]],
                'response_schema' => [
                    'reference_id' => '订单号',
                    'amount' => '金额',
                    'currency_code' => '币种',
                    'status' => '状态',
                ],
            ],
            'pay.voucher' => [
                'name' => '代付订单凭证',
                'method' => 'GET',
                'path_template' => 'api/merchant/payout/voucher/{merchant_id}',
                'params_schema' => [['name' => 'merchant_id', 'required' => true]],
                'response_schema' => ['url' => '凭证图片地址'],
            ],
            'pay.callback.order' => [
                'name' => '回调订单',
                'method' => 'GET',
                'path_template' => 'api/merchant/orders/{merchant_id}/callback',
                'params_schema' => [['name' => 'merchant_id', 'required' => true]],
                'response_schema' => [],
            ],
            'pay.supplement' => [
                'name' => '代收补单',
                'method' => 'POST',
                'path_template' => 'api/merchant/supplement/{merchant_id}',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true],
                    ['name' => 'order_id', 'required' => true],
                    ['name' => 'remark_code', 'required' => true],
                ],
                'response_schema' => [],
            ],
            'pay.approve' => [
                'name' => '交易审批',
                'method' => 'POST',
                'path_template' => 'api/merchant/approve/{merchant_id}',
                'params_schema' => [
                    ['name' => 'merchant_id', 'required' => true],
                    ['name' => 'trade_id', 'required' => true],
                    ['name' => 'action', 'required' => true, 'desc' => 'approve / reject'],
                    ['name' => 'operator', 'required' => true],
                ],
                'response_schema' => ['success' => 'true=操作成功'],
            ],
        ];
    }

    /**
     * 本功能实际调用的接口 code。
     *
     * 不沿用 BaseCustomFeature 的默认派生（会把基类声明的 11 个接口全挂到
     * 每条功能上，后台「上游接口配置」里每个功能都显示一堆无关接口）。
     * 子类按自己 handle() 里真正 callEndpoint 的 code 覆写。
     */
    public static function upstreamEndpoints(): array
    {
        return [];
    }

    public static function defaultConfig(): array
    {
        return [
            'scope_type' => 'group',
            'not_bound_tip' => '本群尚未绑定商户，请先发送 /bd 商户代码',
            'admin_only_tip' => '该命令仅群管理员可用',
        ];
    }

    /**
     * 后台「功能列表 → 配置」自动渲染的表单（每条功能都有，可单独改）
     */
    public static function configSchema(): array
    {
        return [
            ['key' => 'not_bound_tip', 'label' => '未绑定提示', 'type' => 'text', 'required' => false, 'default' => '本群尚未绑定商户，请先发送 /bd 商户代码'],
            ['key' => 'admin_only_tip', 'label' => '非管理员提示', 'type' => 'text', 'required' => false, 'default' => '该命令仅群管理员可用'],
        ];
    }

    /**
     * 每条命令功能的「回复模板」配置项（与 MerchantPayNotifyFeature 一致，
     * 后台「功能列表 → 配置」可以直接改文案，不用改代码重新打包）
     */
    protected static function replySchema(string $default, string $hint): array
    {
        return [
            'key' => 'reply_template',
            'label' => '回复模板',
            'type' => 'template',
            'required' => false,
            'default' => $default,
            'hint' => $hint,
        ];
    }

    /**
     * 渲染回复：后台配了模板就用配的，没配用代码里的默认模板
     */
    protected function renderReply(FeatureContext $context, string $default, array $data = []): string
    {
        return $this->render(
            (string) $context->config('reply_template', $default),
            $data,
            $context->stored
        );
    }

    /**
     * 本群绑定的商户号（跨功能共享）。
     *
     * 拆成一条命令一个功能后，feature_data 是按 feature_id 隔离的：
     * /bd 写的数据 /ye 读不到。统一以 merchant_routes（/bd 写入）为准，
     * 让 11 条命令 + 业务通知 + 审批按钮看到同一份绑定。
     */
    protected function merchantId(FeatureContext $context): ?string
    {
        if ($context->chatId !== null && $context->chatId !== '') {
            try {
                $routes = app(MerchantRouteService::class)->forChat((string) $context->chatId);

                if ($routes !== []) {
                    $bound = trim((string) $routes[0]);

                    if ($bound !== '') {
                        return $bound;
                    }
                }
            } catch (\Throwable) {
                // 路由表读不出来时退回本功能自己的数据，不能挡住业务
            }
        }

        // 历史数据兼容：FeatureExecutor 会把 group_configs.mid 注入 stored，
        // 旧版 /bd 也把 merchant_id 存在自己的 feature_data 里
        $fallback = trim((string) $context->value('merchant_id', ''));

        return $fallback === '' ? null : $fallback;
    }

    protected function notBoundTip(FeatureContext $context): string
    {
        return (string) $context->config('not_bound_tip', '本群尚未绑定商户，请先发送 /bd 商户代码');
    }

    /**
     * @return array<int, string>
     */
    protected function parseOperators(string $raw): array
    {
        preg_match_all('/@?([A-Za-z0-9_]{3,32})/', $raw, $matches);

        return array_values(array_unique(array_filter($matches[1] ?? [])));
    }

    protected function normalizeAmount(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '' || ! preg_match('/^\d+(\.\d{1,8})?$/', $raw) || bccomp($raw, '0', 8) <= 0) {
            return null;
        }

        return $raw;
    }

    protected function isSuccess(array $data): bool
    {
        $value = data_get($data, 'data.success', data_get($data, 'success'));

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    protected function cell(array $row, string $key): string
    {
        $value = data_get($row, $key);

        return $value === null || $value === '' ? '-' : (string) (is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE));
    }

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

    protected function renderRow(array $row): string
    {
        $lines = [];

        foreach ($row as $field => $value) {
            if (str_starts_with((string) $field, '_')) {
                continue;
            }

            $lines[] = $field . '：' . $this->cell($row, (string) $field);
        }

        return implode("\n", $lines);
    }
}
