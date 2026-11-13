<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * /cx 查询订单（固定命令槽位）
 *
 * 独立功能：安装后在「功能列表」里是一行，可单独启停 / 绑定 / 配置回复模板。
 */
class MerchantPayOrderQueryFeature extends MerchantPayFeature
{
    public static function key(): string
    {
        return 'plugin.merchant_pay.order_query';
    }

    public static function uuid(): string
    {
        return 'merchantPayOrderQuery';
    }

    public static function label(): string
    {
        return '订单查询（/cx）';
    }

    public static function featureName(): string
    {
        return '订单查询（/cx）';
    }

    public static function featureDescription(): string
    {
        return '按订单号查询订单，列出上游返回的全部业务字段';
    }

    public static function upstreamEndpoints(): array
    {
        return ['pay.order'];
    }

    public static function commands(): array
    {
        return [
            ['command' => 'cx', 'usage' => '/cx 订单号', 'description' => '查询订单', 'params' => [['name' => 'order_id', 'required' => true]]],
        ];
    }

    public static function configSchema(): array
    {
        return array_merge(parent::configSchema(), [
            static::replySchema("订单 {{order_id}}\n{{detail}}", '可用变量：{{order_id}} {{detail}}（上游业务字段逐行）'),
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
            return $this->fail('用法：/cx 订单号');
        }

        $data = $this->callEndpoint('pay.order', ['merchant_id' => $merchantId], $context, 'GET', rawurlencode($orderId));

        if (isset($data['_error'])) {
            return $this->fail('查询失败：' . $data['_error']);
        }

        $row = $this->businessRow($data);

        if ($row === []) {
            return $this->fail('订单不存在：' . $orderId);
        }

        return $this->reply($this->renderReply(
            $context,
            "订单 {{order_id}}\n{{detail}}",
            ['order_id' => $orderId, 'detail' => $this->renderRow($row)]
        ));
    }
}
