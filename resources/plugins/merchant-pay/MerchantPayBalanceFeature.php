<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * /ye 查询商户余额（固定命令槽位）
 *
 * 独立功能：安装后在「功能列表」里是一行，可单独启停 / 绑定 / 配置回复模板。
 */
class MerchantPayBalanceFeature extends MerchantPayFeature
{
    public static function key(): string
    {
        return 'plugin.merchant_pay.balance';
    }

    public static function uuid(): string
    {
        return 'merchantPayBalance';
    }

    public static function label(): string
    {
        return '查询商户余额（/ye）';
    }

    public static function featureName(): string
    {
        return '查询商户余额（/ye）';
    }

    public static function featureDescription(): string
    {
        return '查询当前群绑定商户的余额与币种（需先 /bd 绑定商户）';
    }

    public static function upstreamEndpoints(): array
    {
        return ['pay.balance'];
    }

    public static function commands(): array
    {
        return [
            ['command' => 'ye', 'usage' => '/ye', 'description' => '查询商户余额（需绑定商户）', 'params' => []],
        ];
    }

    public static function configSchema(): array
    {
        return array_merge(parent::configSchema(), [
            static::replySchema('商户 {{merchant_id}} 余额：{{balance}} {{currency}}', '可用变量：{{merchant_id}} {{balance}} {{currency}}'),
        ]);
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->merchantId($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $data = $this->callEndpoint('pay.balance', ['merchant_id' => $merchantId], $context);

        if (isset($data['_error'])) {
            return $this->fail('查询余额失败：' . $data['_error']);
        }

        $balance = data_get($data, 'data.balance', data_get($data, 'balance'));
        $currency = data_get($data, 'data.currency', data_get($data, 'currency', ''));

        if (! is_scalar($balance) || trim((string) $balance) === '') {
            return $this->fail('上游未返回余额：请确认该上游已配适配器（标准字段 balance）');
        }

        return $this->reply($this->renderReply(
            $context,
            '商户 {{merchant_id}} 余额：{{balance}} {{currency}}',
            ['merchant_id' => $merchantId, 'balance' => (string) $balance, 'currency' => (string) $currency]
        ));
    }
}
