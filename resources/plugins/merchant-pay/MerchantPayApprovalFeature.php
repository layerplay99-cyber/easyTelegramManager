<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\OperatorPolicy;

/**
 * 商户支付插件 · 审批按钮回调（callback_query）
 *
 * 业务通知里「同意 / 驳回」按钮的 callback_data 约定：
 *   payapprove:{trade_id} / payreject:{trade_id}
 *
 * 处理流程：
 *   1. 只有本群配置的审批人（/trsq 保存）或群管理员能操作，其他人点按钮回提示
 *   2. 推送到上游审批接口（pay.approve）
 *   3. 群内就地编辑消息反馈结果（原按钮消息改成「已同意/已驳回 by @xxx」）
 */
class MerchantPayApprovalFeature extends BaseCustomFeature
{
    public static function key(): string
    {
        return 'plugin.merchant_pay_approval';
    }

    /**
     * 功能标识（features.feature = custom:{uuid}）：显式固定，避免类名调整改变标识
     */
    public static function uuid(): string
    {
        return 'merchantPayApproval';
    }

    public static function label(): string
    {
        return '商户支付审批按钮';
    }

    public static function featureName(): string
    {
        return '商户支付审批按钮';
    }

    public static function featureDescription(): string
    {
        return '处理业务通知里的同意/驳回按钮：校验操作人 → 推送上游 → 群内反馈';
    }

    public static function group(): string
    {
        return '三方支付';
    }

    public static function triggerName(): string
    {
        return 'callback_query';
    }

    public static function commands(): array
    {
        return [];
    }

    public static function defaultConfig(): array
    {
        return ['scope_type' => 'group'];
    }

    public static function configSchema(): array
    {
        return [];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $callback = (array) $context->payload('callback_query', []);
        $data = (string) ($callback['data'] ?? '');

        if (! preg_match('/^(payapprove|payreject):(.+)$/', $data, $matches)) {
            return FeatureResult::fail('不是本功能的按钮回调');
        }

        $action = $matches[1] === 'payapprove' ? 'approve' : 'reject';
        $tradeId = trim($matches[2]);
        $chatId = $context->chatId;
        $userId = (int) ($callback['from']['id'] ?? 0);

        $operator = (string) ($callback['from']['username'] ?? '');

        if (! $this->canApprove($context, $chatId, $userId, $operator)) {
            return FeatureResult::fail('你没有审批权限');
        }

        $merchantId = trim((string) $context->value('merchant_id', ''));

        if ($merchantId === '') {
            return FeatureResult::fail('本群尚未绑定商户，无法提交审批');
        }

        $data2 = $this->callEndpoint('pay.approve', [
            'merchant_id' => $merchantId,
            'trade_id' => $tradeId,
            'action' => $action,
            'operator' => $operator !== '' ? '@' . $operator : (string) $userId,
        ], $context, 'POST');

        $ok = ! isset($data2['_error'])
            && filter_var(data_get($data2, 'data.success', data_get($data2, 'success', true)), FILTER_VALIDATE_BOOLEAN);

        $label = $action === 'approve' ? '同意' : '驳回';

        // 群内就地反馈：改掉原消息的按钮，避免重复点击
        if ($context->telegram && $chatId !== null) {
            try {
                $context->telegram->editMessageReplyMarkup([
                    'chat_id' => $chatId,
                    'message_id' => (int) ($callback['message']['message_id'] ?? 0),
                    'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
                ]);

                $context->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $ok
                        ? "审批结果：交易 {$tradeId} 已{$label}（操作人 @{$operator}）"
                        : "审批结果：交易 {$tradeId} {$label}提交失败，请重试",
                ]);
            } catch (\Throwable) {
                // 消息编辑失败不影响审批结论
            }
        }

        return $ok
            ? FeatureResult::ok("已{$label}")
            : FeatureResult::fail('上游未能确认审批结果');
    }

    /**
     * 审批权限：/trsq 设的白名单优先（管理员始终放行），没设过就只认群管理员
     *
     * 判定统一走 OperatorPolicy：管理员身份问机器人 getChatMember（实时），
     * 白名单与 /trsq 写的是同一份数据，不各存一套。
     */
    protected function canApprove(FeatureContext $context, int|string|null $chatId, int $userId, string $operator): bool
    {
        if ($chatId === null || $userId <= 0) {
            return false;
        }

        $policy = app(OperatorPolicy::class);
        $settings = $policy->settings((string) $chatId);

        return $policy->allows($settings, (string) $chatId, $userId, $operator, $context->bot);
    }
}
