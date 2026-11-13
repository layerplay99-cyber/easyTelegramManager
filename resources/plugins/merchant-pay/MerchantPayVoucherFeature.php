<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * /pz 代付凭证（固定命令槽位）
 *
 * 独立功能：安装后在「功能列表」里是一行，可单独启停 / 绑定 / 配置回复模板。
 * 上游给的是图片地址：优先直接发图，失败退回发链接。
 */
class MerchantPayVoucherFeature extends MerchantPayFeature
{
    public static function key(): string
    {
        return 'plugin.merchant_pay.voucher';
    }

    public static function uuid(): string
    {
        return 'merchantPayVoucher';
    }

    public static function label(): string
    {
        return '代付凭证（/pz）';
    }

    public static function featureName(): string
    {
        return '代付凭证（/pz）';
    }

    public static function featureDescription(): string
    {
        return '按支付订单号取上游凭证图片并发到群（失败时退回发链接）';
    }

    public static function upstreamEndpoints(): array
    {
        return ['pay.voucher'];
    }

    public static function commands(): array
    {
        return [
            ['command' => 'pz', 'usage' => '/pz 支付订单号', 'description' => '获取代付订单凭证图', 'params' => [['name' => 'order_id', 'required' => true]]],
        ];
    }

    public static function configSchema(): array
    {
        return array_merge(parent::configSchema(), [
            static::replySchema("订单凭证：{{order_id}}\n{{url}}", '可用变量：{{order_id}} {{url}}（发图片时作为图片说明）'),
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
            ? $this->reply($this->renderReply(
                $context,
                "订单凭证：{{order_id}}\n{{url}}",
                ['order_id' => $orderId, 'url' => $url]
            ))
            : $this->fail('上游未返回凭证图片');
    }
}
