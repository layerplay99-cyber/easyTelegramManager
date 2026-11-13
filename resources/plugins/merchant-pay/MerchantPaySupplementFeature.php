<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * /bud 代收补单（固定命令槽位）
 *
 * 独立功能：安装后在「功能列表」里是一行，可单独启停 / 绑定 / 配置回复模板。
 */
class MerchantPaySupplementFeature extends MerchantPayFeature
{
    public static function key(): string
    {
        return 'plugin.merchant_pay.supplement';
    }

    public static function uuid(): string
    {
        return 'merchantPaySupplement';
    }

    public static function label(): string
    {
        return '代收补单（/bud）';
    }

    public static function featureName(): string
    {
        return '代收补单（/bud）';
    }

    public static function featureDescription(): string
    {
        return '按订单号与备注码发起代收补单，回显上游受理结果';
    }

    public static function upstreamEndpoints(): array
    {
        return ['pay.supplement'];
    }

    public static function commands(): array
    {
        return [
            ['command' => 'bud', 'usage' => '/bud 订单号 备注码', 'description' => '代收补单', 'params' => [['name' => 'order_id', 'required' => true], ['name' => 'remark_code', 'required' => true]]],
        ];
    }

    public static function configSchema(): array
    {
        return array_merge(parent::configSchema(), [
            static::replySchema("补单结果（订单 {{order_id}}）\n{{detail}}", '可用变量：{{order_id}} {{detail}}（上游业务字段逐行）'),
        ]);
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->merchantId($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $orderId = trim((string) ($context->arg(0) ?? ''));
        $remark = trim((string) ($context->arg(1) ?? ''));

        if ($orderId === '' || $remark === '') {
            return $this->fail('用法：/bud 订单号 备注码');
        }

        $data = $this->callEndpoint('pay.supplement', [
            'merchant_id' => $merchantId,
            'order_id' => $orderId,
            'remark_code' => $remark,
        ], $context, 'POST');

        if (isset($data['_error'])) {
            return $this->fail('补单失败：' . $data['_error']);
        }

        $row = $this->businessRow($data);

        return $this->reply($this->renderReply(
            $context,
            "补单结果（订单 {{order_id}}）\n{{detail}}",
            ['order_id' => $orderId, 'detail' => $row === [] ? '上游已受理' : $this->renderRow($row)]
        ));
    }
}
