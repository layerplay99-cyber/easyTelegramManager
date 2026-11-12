<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\GroupAdmins;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\MerchantRouteService;

/**
 * 商户支付插件 · 命令组（三方支付业务）
 *
 * 覆盖需求里的 12 项业务：
 *   /bd 绑定商户      /jb 解绑商户     /ye 商户余额    /xq 代付审批限额查询
 *   /yj 余额不足提醒  /xe 设置审批限额  /cx 订单查询    /trsq 设置审批人员
 *   /pz 代付凭证      /ht 回调订单      /bud 代收补单
 *   业务通知见 MerchantPayNotifyFeature（webhook），审批按钮见 MerchantPayApprovalFeature
 *
 * 上游接口一律通过 callEndpoint('pay.xxx') 走平台适配器，响应归一成标准结构；
 * 商户号按群作用域持久化（putData），同时写入 merchant_routes 供业务通知路由使用。
 * 所有 data 的具体映射由开发人员在 endpoints() 的 response_schema 里按上游文档补。
 */
class MerchantPayCommandFeature extends BaseCustomFeature implements EndpointProvider
{
    public static function key(): string
    {
        return 'plugin.merchant_pay';
    }

    /**
     * 功能标识（features.feature = custom:{uuid}）：显式固定，避免类名调整改变标识
     */
    public static function uuid(): string
    {
        return 'merchantPay';
    }

    public static function label(): string
    {
        return '商户支付（三方）';
    }

    public static function featureName(): string
    {
        return '商户支付（三方）';
    }

    public static function featureDescription(): string
    {
        return '三方支付业务命令组：/bd /jb /ye /xq /yj /xe /cx /trsq /pz /ht /bud';
    }

    public static function group(): string
    {
        return '三方支付';
    }

    /**
     * 上游接口声明（code 前缀 pay.，不与内置接口冲突）
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

    public static function commands(): array
    {
        return [
            ['command' => 'bd', 'usage' => '/bd 商户代码', 'description' => '绑定商户（校验上游后绑定本群）', 'params' => [['name' => 'merchant_id', 'required' => false, 'hint' => '留空查看当前绑定']]],
            ['command' => 'jb', 'usage' => '/jb 商户代码', 'description' => '解绑本群商户', 'params' => [['name' => 'merchant_id', 'required' => false]]],
            ['command' => 'ye', 'usage' => '/ye', 'description' => '查询商户余额（需绑定商户）', 'params' => []],
            ['command' => 'xq', 'usage' => '/xq', 'description' => '查询代付审批限额', 'params' => []],
            ['command' => 'yj', 'usage' => '/yj 金额', 'description' => '设置余额不足提醒', 'params' => [['name' => 'amount', 'required' => true]]],
            ['command' => 'xe', 'usage' => '/xe 金额1 金额2', 'description' => '设置代付审批限额', 'params' => [['name' => 'min', 'required' => true], ['name' => 'max', 'required' => true]]],
            ['command' => 'cx', 'usage' => '/cx 订单号', 'description' => '查询订单', 'params' => [['name' => 'order_id', 'required' => true]]],
            ['command' => 'trsq', 'usage' => '/trsq @用户1 @用户2', 'description' => '设置审批人员（仅群管理员）', 'params' => [['name' => 'operators', 'required' => true]]],
            ['command' => 'pz', 'usage' => '/pz 支付订单号', 'description' => '获取代付订单凭证图', 'params' => [['name' => 'order_id', 'required' => true]]],
            ['command' => 'ht', 'usage' => '/ht 订单号', 'description' => '回调订单（补推上游状态）', 'params' => [['name' => 'order_id', 'required' => true]]],
            ['command' => 'bud', 'usage' => '/bud 订单号 备注码', 'description' => '代收补单', 'params' => [['name' => 'order_id', 'required' => true], ['name' => 'remark_code', 'required' => true]]],
        ];
    }

    public static function defaultConfig(): array
    {
        return [
            'scope_type' => 'group',
            'not_bound_tip' => '本群尚未绑定商户，请先发送 /bd 商户代码',
            'admin_only_tip' => '该命令仅群管理员可用',
        ];
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'not_bound_tip', 'label' => '未绑定提示', 'type' => 'text', 'required' => false, 'default' => '本群尚未绑定商户，请先发送 /bd 商户代码'],
            ['key' => 'admin_only_tip', 'label' => '非管理员提示', 'type' => 'text', 'required' => false, 'default' => '该命令仅群管理员可用'],
            ['key' => 'approvers', 'label' => '审批人员', 'type' => 'textarea', 'required' => false, 'default' => '', 'hint' => '每行一个 @用户名 或用户 ID，交易通知里带同意/驳回按钮'],
        ];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $command = strtolower(ltrim($context->command, '/'));

        return match ($command) {
            'bd' => $this->bind($context),
            'jb' => $this->unbind($context),
            'ye' => $this->balance($context),
            'xq' => $this->queryLimit($context),
            'yj' => $this->setWarn($context),
            'xe' => $this->setLimit($context),
            'cx' => $this->queryOrder($context),
            'trsq' => $this->setApprovers($context),
            'pz' => $this->voucher($context),
            'ht' => $this->callbackOrder($context),
            'bud' => $this->supplement($context),
            default => $this->fail('未知命令：' . $command),
        };
    }

    // ---------------------------------------------------------------- 绑定 / 解绑

    protected function bind(FeatureContext $context): FeatureResult
    {
        $merchantId = trim((string) ($context->arg(0) ?? ''));

        if ($merchantId === '') {
            $bound = trim((string) $context->value('merchant_id', ''));

            return $bound === ''
                ? $this->fail((string) $context->config('not_bound_tip', '本群尚未绑定商户'))
                : $this->reply("本群绑定的商户：{$bound}");
        }

        $check = $this->callEndpoint('pay.check', ['merchant_id' => $merchantId], $context);

        if (isset($check['_error'])) {
            return $this->fail('校验商户失败：' . $check['_error']);
        }

        $returned = data_get($check, 'data.id', data_get($check, 'id'));

        if ($returned === null || (string) $returned !== $merchantId) {
            return $this->fail('商户代码不存在：' . $merchantId);
        }

        $this->putData($context, ['merchant_id' => $merchantId]);

        // 同步写路由表：业务通知（HookController）按「群 ↔ 商户号」判断能否投递
        if ($context->bot && $context->chatId) {
            app(MerchantRouteService::class)->bind(
                (int) $context->bot->id,
                (string) $context->chatId,
                $merchantId,
                null,
                (int) $context->feature->id
            );
        }

        return $this->reply("已绑定商户：{$merchantId}");
    }

    protected function unbind(FeatureContext $context): FeatureResult
    {
        $bound = trim((string) $context->value('merchant_id', ''));

        if ($bound === '') {
            return $this->fail('该群尚未绑定商户');
        }

        $input = trim((string) ($context->arg(0) ?? ''));

        if ($input !== '' && $input !== $bound) {
            return $this->fail("参数商户与已绑定商户不一致：当前绑定 {$bound}");
        }

        if ($context->bot && $context->chatId) {
            app(MerchantRouteService::class)->unbind((int) $context->bot->id, (string) $context->chatId);
        }

        $this->putData($context, ['merchant_id' => '']);

        return $this->reply("已解绑商户：{$bound}");
    }

    // ---------------------------------------------------------------- 查询类

    protected function balance(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->requireMerchant($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $data = $this->callEndpoint('pay.balance', ['merchant_id' => $merchantId], $context);

        if (isset($data['_error'])) {
            return $this->fail('查询余额失败：' . $data['_error']);
        }

        $balance = data_get($data, 'data.balance', data_get($data, 'balance'));
        $currency = data_get($data, 'data.currency', data_get($data, 'currency', ''));

        return is_scalar($balance) && trim((string) $balance) !== ''
            ? $this->reply("商户 {$merchantId} 余额：{$balance} {$currency}")
            : $this->fail('上游未返回余额：请确认该上游已配适配器（标准字段 balance）');
    }

    protected function queryLimit(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->requireMerchant($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $data = $this->callEndpoint('pay.limit.query', ['merchant_id' => $merchantId], $context);

        if (isset($data['_error'])) {
            return $this->fail('查询限额失败：' . $data['_error']);
        }

        $row = data_get($data, 'data', $data);

        return $this->reply(sprintf(
            "代付审批限额（商户 %s）：\n单笔下限：%s\n单笔上限：%s\n币种：%s",
            $merchantId,
            $this->cell($row, 'min'),
            $this->cell($row, 'max'),
            $this->cell($row, 'currency')
        ));
    }

    protected function queryOrder(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->requireMerchant($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $orderId = trim((string) ($context->arg(0) ?? ''));

        if ($orderId === '') {
            return $this->fail('用法：/cx 订单号');
        }

        $data = $this->callEndpoint('pay.order', ['merchant_id' => $merchantId], $context, 'GET', rawurlencode($orderId));

        if (isset($data['_error'])) {
            return $this->fail('查询失败：' . $data['_error']);
        }

        $row = $this->businessRow($data);

        return $row === []
            ? $this->fail('订单不存在：' . $orderId)
            : $this->reply("订单 {$orderId}\n" . $this->renderRow($row));
    }

    protected function callbackOrder(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->requireMerchant($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $orderId = trim((string) ($context->arg(0) ?? ''));

        if ($orderId === '') {
            return $this->fail('用法：/ht 订单号');
        }

        $data = $this->callEndpoint('pay.callback.order', ['merchant_id' => $merchantId], $context, 'GET', rawurlencode($orderId));

        if (isset($data['_error'])) {
            return $this->fail('回调失败：' . $data['_error']);
        }

        $row = $this->businessRow($data);

        return $this->reply("回调结果（订单 {$orderId}）\n" . ($row === [] ? '上游未返回内容' : $this->renderRow($row)));
    }

    // ---------------------------------------------------------------- 设置类

    protected function setWarn(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->requireMerchant($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $amount = $this->normalizeAmount((string) ($context->arg(0) ?? ''));

        if ($amount === null) {
            return $this->fail('金额格式不正确，用法：/yj 金额');
        }

        $data = $this->callEndpoint('pay.warn.set', ['merchant_id' => $merchantId, 'threshold' => $amount], $context, 'POST');

        if (isset($data['_error'])) {
            return $this->fail('设置失败：' . $data['_error']);
        }

        return $this->reply($this->isSuccess($data)
            ? "余额不足提醒已设置：低于 {$amount} 提醒"
            : '设置失败：上游返回未成功');
    }

    protected function setLimit(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->requireMerchant($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $min = $this->normalizeAmount((string) ($context->arg(0) ?? ''));
        $max = $this->normalizeAmount((string) ($context->arg(1) ?? ''));

        if ($min === null || $max === null) {
            return $this->fail('用法：/xe 金额1 金额2（单笔下限、单笔上限）');
        }

        if (bccomp($min, $max, 8) > 0) {
            return $this->fail('下限不能大于上限');
        }

        $data = $this->callEndpoint('pay.limit.set', ['merchant_id' => $merchantId, 'min' => $min, 'max' => $max], $context, 'POST');

        if (isset($data['_error'])) {
            return $this->fail('设置失败：' . $data['_error']);
        }

        return $this->reply($this->isSuccess($data)
            ? "代付审批限额已设置：{$min} ~ {$max}"
            : '设置失败：上游返回未成功');
    }

    /**
     * /trsq @用户1 @用户2：保存审批人员，并推给上游
     * 默认仅群管理员可用（群管理员台账 group_admins）
     */
    protected function setApprovers(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->requireMerchant($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        if (! $this->isGroupAdmin($context)) {
            return $this->fail((string) $context->config('admin_only_tip', '该命令仅群管理员可用'));
        }

        $operators = $this->parseOperators(implode(' ', $context->args));

        if ($operators === []) {
            return $this->fail('用法：/trsq @用户1 @用户2');
        }

        // 平台侧保存：交易通知按它决定推送给谁、谁能点按钮
        $this->putData($context, ['approvers' => $operators]);

        $data = $this->callEndpoint(
            'pay.operator.set',
            ['merchant_id' => $merchantId, 'operators' => implode(',', $operators)],
            $context,
            'POST'
        );

        if (isset($data['_error'])) {
            return $this->fail('已保存到平台，但推送上游失败：' . $data['_error']);
        }

        return $this->reply('审批人员已设置：' . implode('、', array_map(fn ($o) => '@' . $o, $operators)));
    }

    // ---------------------------------------------------------------- 凭证 / 补单

    protected function voucher(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->requireMerchant($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $orderId = trim((string) ($context->arg(0) ?? ''));

        if ($orderId === '') {
            return $this->fail('用法：/pz 支付订单号');
        }

        $data = $this->callEndpoint('pay.voucher', ['merchant_id' => $merchantId], $context, 'GET', rawurlencode($orderId));

        if (isset($data['_error'])) {
            return $this->fail('获取凭证失败：' . $data['_error']);
        }

        $url = (string) data_get($data, 'data.url', data_get($data, 'url', ''));

        // 凭证是图片：直接发图，失败再退回发链接
        if ($url !== '' && $context->telegram && $context->chatId) {
            try {
                $context->telegram->sendPhoto([
                    'chat_id' => $context->chatId,
                    'photo' => $url,
                    'caption' => '订单凭证：' . $orderId,
                ]);

                return FeatureResult::ok('凭证已发送');
            } catch (\Throwable) {
                // 落到下面发链接
            }
        }

        return $url !== ''
            ? $this->reply("订单凭证：{$orderId}\n{$url}")
            : $this->fail('上游未返回凭证图片');
    }

    protected function supplement(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->requireMerchant($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $orderId = trim((string) ($context->arg(0) ?? ''));
        $remark = trim((string) ($context->arg(1) ?? ''));

        if ($orderId === '' || $remark === '') {
            return $this->fail('用法：/bud 订单号 备注码');
        }

        $data = $this->callEndpoint(
            'pay.supplement',
            ['merchant_id' => $merchantId, 'order_id' => $orderId, 'remark_code' => $remark],
            $context,
            'POST'
        );

        if (isset($data['_error'])) {
            return $this->fail('补单失败：' . $data['_error']);
        }

        $row = $this->businessRow($data);

        return $this->reply("补单结果（订单 {$orderId}）\n" . ($row === [] ? '上游已受理' : $this->renderRow($row)));
    }

    // ---------------------------------------------------------------- 工具

    protected function requireMerchant(FeatureContext $context): ?string
    {
        $merchantId = trim((string) $context->value('merchant_id', ''));

        return $merchantId === '' ? null : $merchantId;
    }

    protected function notBoundTip(FeatureContext $context): string
    {
        return (string) $context->config('not_bound_tip', '本群尚未绑定商户，请先发送 /bd 商户代码');
    }

    protected function isGroupAdmin(FeatureContext $context): bool
    {
        if (! $context->chatId || ! $context->userId) {
            return false;
        }

        return GroupAdmins::query()
            ->where('chat_id', (string) $context->chatId)
            ->where('user_id', (int) $context->userId)
            ->exists();
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
