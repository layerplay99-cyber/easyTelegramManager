<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\OperatorPolicy;

/**
 * /trsq 设置审批人员（固定命令槽位）
 *
 * 独立功能：安装后在「功能列表」里是一行，可单独启停 / 绑定 / 配置回复模板。
 *
 * 谁能用：群主 / 管理员，以机器人 getChatMember 的实时结果为准（OperatorPolicy）。
 * 保存到哪：OperatorPolicy 的群级白名单（feature_data feature_id=0），
 * 审批按钮的权限判定与它同一份数据，后台「审批人员」配置也是同一处。
 */
class MerchantPayApproversFeature extends MerchantPayFeature
{
    public static function key(): string
    {
        return 'plugin.merchant_pay.approvers';
    }

    public static function uuid(): string
    {
        return 'merchantPayApprovers';
    }

    public static function label(): string
    {
        return '设置审批人员（/trsq）';
    }

    public static function featureName(): string
    {
        return '设置审批人员（/trsq）';
    }

    public static function featureDescription(): string
    {
        return '群管理员设置审批人员白名单（@用户名），并同步上下游；审批按钮权限同源';
    }

    public static function upstreamEndpoints(): array
    {
        return ['pay.operator.set'];
    }

    public static function commands(): array
    {
        return [
            ['command' => 'trsq', 'usage' => '/trsq @用户1 @用户2', 'description' => '设置审批人员（仅群管理员）', 'params' => [['name' => 'operators', 'required' => true]]],
        ];
    }

    public static function configSchema(): array
    {
        return array_merge(parent::configSchema(), [
            ['key' => 'approvers', 'label' => '审批人员', 'type' => 'textarea', 'required' => false, 'default' => '', 'hint' => '每行一个 @用户名 或用户 ID，交易通知里带同意/驳回按钮'],
            static::replySchema('审批人员已设置：{{operators}}', '可用变量：{{operators}} {{admin_count}} {{merchant_id}}'),
        ]);
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->merchantId($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $chatId = (string) $context->chatId;
        $policy = app(OperatorPolicy::class);

        if (! $policy->isGroupAdmin($chatId, $context->userId, $context->bot)) {
            return $this->fail((string) $context->config('admin_only_tip', '该命令仅群管理员可用'));
        }

        $tokens = array_values(array_filter(array_map('trim', $context->args)));
        $operators = $this->parseOperators(implode(' ', $tokens));

        if ($operators === []) {
            return $this->fail('用法：/trsq @用户1 @用户2（@ 后面跟群成员用户名或用户 ID）');
        }

        // 群成员表能查到的存 user_id（用户名随时会改），查不到的按用户名兜底
        $targets = $policy->parseTargets($chatId, $tokens);
        $policy->save($chatId, $targets['ids'], $targets['names']);

        // 顺手把群管理员全量同步进 group_admins（后台列表 / 兜底判定用）
        $adminCount = $context->bot ? $policy->syncAdmins($chatId, $context->bot) : 0;

        $data = $this->callEndpoint('pay.operator.set', [
            'merchant_id' => $merchantId,
            'operators' => implode(',', $operators),
        ], $context, 'POST');

        if (isset($data['_error'])) {
            return $this->fail('已保存到平台，但推送上游失败：' . $data['_error']);
        }

        $message = $this->renderReply(
            $context,
            '审批人员已设置：{{operators}}',
            [
                'operators' => implode('、', array_map(fn ($o) => '@' . $o, $operators)),
                'admin_count' => $adminCount,
                'merchant_id' => $merchantId,
            ]
        );

        if ($adminCount > 0) {
            $message .= sprintf('（已同步群管理员 %d 人）', $adminCount);
        }

        return $this->reply($message);
    }
}
