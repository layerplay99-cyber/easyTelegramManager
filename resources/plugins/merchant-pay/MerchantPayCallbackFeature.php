<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * /ht 回调订单（固定命令槽位）
 *
 * 独立功能：安装后在「功能列表」里是一行，可单独启停 / 绑定 / 配置回复模板。
 */
class MerchantPayCallbackFeature extends MerchantPayFeature
{
    public static function key(): string
    {
        return 'plugin.merchant_pay.callback_order';
    }

    public static function uuid(): string
    {
        return 'merchantPayCallbackOrder';
    }

    public static function label(): string
    {
        return '回调订单（/ht）';
    }

    public static function featureName(): string
    {
        return '回调订单（/ht）';
    }

    public static function featureDescription(): string
    {
        return '按订单号补推上游回调，回显上游返回的业务字段';
    }

    public static function upstreamEndpoints(): array
    {
        return ['pay.callback.order'];
    }

    public static function commands(): array
    {
        return [
            ['command' => 'ht', 'usage' => '/ht 订单号', 'description' => '回调订单（补推上游状态）', 'params' => [['name' => 'order_id', 'required' => true]]],
        ];
    }

    public static function configSchema(): array
    {
        return array_merge(parent::configSchema(), [
            static::replySchema("回调结果（订单 {{order_id}}）\n{{detail}}", '可用变量：{{order_id}} {{detail}}（上游业务字段逐行）'),
        ]);
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->merchantId($context);

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

        return $this->reply($this->renderReply(
            $context,
            "回调结果（订单 {{order_id}}）\n{{detail}}",
            ['order_id' => $orderId, 'detail' => $row === [] ? '上游未返回内容' : $this->renderRow($row)]
        ));
    }
}
