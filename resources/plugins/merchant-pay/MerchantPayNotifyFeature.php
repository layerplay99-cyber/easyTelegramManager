<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;

/**
 * 商户支付插件 · 业务通知（webhook）
 *
 * 适用该业务本身、不通用：上游按 POST /api/hooks/{token} 投递，
 * 平台收到后按这里的逻辑判断、解析，再推到群里。
 *
 * 三件事：
 *   1. 只处理「本群绑定的商户」的通知（HookController 已按 merchant_routes 白名单拦过一层，
 *      这里再按业务字段兜底，防止商户号写在 payload 里的情况）
 *   2. 解析业务字段（金额/订单号/状态/类型）渲染成群消息
 *   3. 若本群配了审批人员，交易类通知附「同意 / 驳回」按钮（回调见 MerchantPayApprovalFeature）
 *
 * 自定义解构点：normalize() —— 上游返回什么字段由开发人员在这里映射，
 * 映射后的标准字段：type / order_id / amount / currency / status / trade_id。
 */
class MerchantPayNotifyFeature extends BaseCustomFeature
{
    public static function key(): string
    {
        return 'plugin.merchant_pay_notify';
    }

    /**
     * 功能标识（features.feature = custom:{uuid}）：显式固定，避免类名调整改变标识
     */
    public static function uuid(): string
    {
        return 'merchantPayNotify';
    }

    public static function label(): string
    {
        return '商户支付业务通知';
    }

    public static function featureName(): string
    {
        return '商户支付业务通知';
    }

    public static function featureDescription(): string
    {
        return '接收上游业务通知并推送到群；配了审批人员的群附「同意/驳回」按钮';
    }

    public static function group(): string
    {
        return '三方支付';
    }

    public static function triggerName(): string
    {
        return 'webhook';
    }

    public static function defaultConfig(): array
    {
        return [
            'scope_type' => 'group',
            'title' => '业务通知',
            'template' => "【{{title}}】\n类型：{{type}}\n订单：{{order_id}}\n金额：{{amount}} {{currency}}\n状态：{{status}}",
        ];
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'title', 'label' => '通知标题', 'type' => 'text', 'required' => false, 'default' => '业务通知'],
            [
                'key' => 'template',
                'label' => '通知模板',
                'type' => 'template',
                'required' => false,
                'default' => "【{{title}}】\n类型：{{type}}\n订单：{{order_id}}\n金额：{{amount}} {{currency}}\n状态：{{status}}",
                'hint' => '可用变量：{{title}} {{type}} {{order_id}} {{amount}} {{currency}} {{status}}',
            ],
        ];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $payload = (array) $context->payload('payload', []);

        if ($payload === []) {
            return FeatureResult::fail('空报文');
        }

        $row = $this->normalize($payload);

        // 商户校验：报文里带商户号时，必须是本群绑定的商户
        $payloadMerchant = (string) ($row['merchant_id'] ?? '');

        if ($payloadMerchant !== '') {
            $bound = trim((string) $context->value('merchant_id', ''));

            if ($bound !== '' && $bound !== $payloadMerchant) {
                return FeatureResult::fail("该群绑定的是商户 {$bound}，忽略商户 {$payloadMerchant} 的通知");
            }
        }

        $text = $this->render(
            (string) $context->config('template', "【{{title}}】\n类型：{{type}}\n订单：{{order_id}}"),
            [
                'title' => (string) $context->config('title', '业务通知'),
                'type' => $row['type'] ?? '-',
                'order_id' => $row['order_id'] ?? '-',
                'amount' => $row['amount'] ?? '-',
                'currency' => $row['currency'] ?? '',
                'status' => $row['status'] ?? '-',
            ],
            $context
        );

        $targetChatId = $context->targetChatId ?? $context->chatId;

        if ($targetChatId === null) {
            return FeatureResult::fail('未解析出目标群');
        }

        // 交易类通知 + 已配置审批人 → 带同意/驳回按钮
        $approvers = (array) $context->value('approvers', []);
        $tradeId = trim((string) ($row['trade_id'] ?? ''));

        if ($approvers !== [] && $tradeId !== '' && $context->telegram) {
            $this->sendWithApproval($context, $targetChatId, $text, $tradeId);

            return FeatureResult::ok('已推送（带审批按钮）');
        }

        if ($context->telegram) {
            $context->telegram->sendMessage([
                'chat_id' => $targetChatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
        }

        return FeatureResult::ok('已推送');
    }

    /**
     * 自定义解构：把上游报文映射成标准字段（开发人员按上游文档补）
     *
     * @return array{type?: string, order_id?: string, amount?: string, currency?: string, status?: string, trade_id?: string, merchant_id?: string}
     */
    protected function normalize(array $payload): array
    {
        // 上游常见形态：{ code, data: { ... } } / { ... }
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        return [
            'type' => (string) ($data['type'] ?? $data['trade_type'] ?? ''),
            'order_id' => (string) ($data['order_id'] ?? $data['reference_id'] ?? $data['transaction_id'] ?? ''),
            'amount' => (string) ($data['amount'] ?? ''),
            'currency' => (string) ($data['currency'] ?? $data['currency_code'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
            'trade_id' => (string) ($data['trade_id'] ?? $data['id'] ?? ''),
            'merchant_id' => (string) ($data['merchant_id'] ?? $payload['merchant_id'] ?? ''),
        ];
    }

    protected function sendWithApproval(FeatureContext $context, int|string $chatId, string $text, string $tradeId): void
    {
        $context->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '同意', 'callback_data' => 'payapprove:' . $tradeId],
                    ['text' => '驳回', 'callback_data' => 'payreject:' . $tradeId],
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
