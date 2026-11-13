<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * /yj 设置余额不足提醒（固定命令槽位）
 *
 * 独立功能：安装后在「功能列表」里是一行，可单独启停 / 绑定 / 配置回复模板。
 */
class MerchantPayWarnFeature extends MerchantPayFeature
{
    public static function key(): string
    {
        return 'plugin.merchant_pay.warn';
    }

    public static function uuid(): string
    {
        return 'merchantPayWarn';
    }

    public static function label(): string
    {
        return '设置余额不足提醒（/yj）';
    }

    public static function featureName(): string
    {
        return '设置余额不足提醒（/yj）';
    }

    public static function featureDescription(): string
    {
        return '按金额设置商户余额不足提醒阈值并推送上游';
    }

    public static function upstreamEndpoints(): array
    {
        return ['pay.warn.set'];
    }

    public static function commands(): array
    {
        return [
            ['command' => 'yj', 'usage' => '/yj 金额', 'description' => '设置余额不足提醒', 'params' => [['name' => 'amount', 'required' => true]]],
        ];
    }

    public static function configSchema(): array
    {
        return array_merge(parent::configSchema(), [
            static::replySchema('余额不足提醒已设置：低于 {{amount}} 提醒', '可用变量：{{amount}} {{merchant_id}}'),
        ]);
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->merchantId($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $amount = $this->normalizeAmount((string) ($context->arg(0) ?? ''));

        if ($amount === null) {
            return $this->fail('金额格式不正确，用法：/yj 金额');
        }

        $data = $this->callEndpoint('pay.warn.set', [
            'merchant_id' => $merchantId,
            'threshold' => $amount,
        ], $context, 'POST');

        if (isset($data['_error'])) {
            return $this->fail('设置失败：' . $data['_error']);
        }

        if (! $this->isSuccess($data)) {
            return $this->fail('设置失败：上游返回未成功');
        }

        return $this->reply($this->renderReply(
            $context,
            '余额不足提醒已设置：低于 {{amount}} 提醒',
            ['amount' => $amount, 'merchant_id' => $merchantId]
        ));
    }
}
