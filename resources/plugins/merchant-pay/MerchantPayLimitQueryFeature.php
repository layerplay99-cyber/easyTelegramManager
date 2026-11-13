<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * /xq 查询代付审批限额（固定命令槽位）
 *
 * 独立功能：安装后在「功能列表」里是一行，可单独启停 / 绑定 / 配置回复模板。
 */
class MerchantPayLimitQueryFeature extends MerchantPayFeature
{
    public static function key(): string
    {
        return 'plugin.merchant_pay.limit_query';
    }

    public static function uuid(): string
    {
        return 'merchantPayLimitQuery';
    }

    public static function label(): string
    {
        return '查询代付审批限额（/xq）';
    }

    public static function featureName(): string
    {
        return '查询代付审批限额（/xq）';
    }

    public static function featureDescription(): string
    {
        return '查询当前群绑定商户的单笔代付审批下限 / 上限 / 币种';
    }

    public static function upstreamEndpoints(): array
    {
        return ['pay.limit.query'];
    }

    public static function commands(): array
    {
        return [
            ['command' => 'xq', 'usage' => '/xq', 'description' => '查询代付审批限额', 'params' => []],
        ];
    }

    public static function configSchema(): array
    {
        return array_merge(parent::configSchema(), [
            static::replySchema("代付审批限额（商户 {{merchant_id}}）：\n单笔下限：{{min}}\n单笔上限：{{max}}\n币种：{{currency}}", '可用变量：{{merchant_id}} {{min}} {{max}} {{currency}}'),
        ]);
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->merchantId($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $data = $this->callEndpoint('pay.limit.query', ['merchant_id' => $merchantId], $context);

        if (isset($data['_error'])) {
            return $this->fail('查询限额失败：' . $data['_error']);
        }

        $row = data_get($data, 'data', $data);
        $row = is_array($row) ? $row : [];

        return $this->reply($this->renderReply(
            $context,
            "代付审批限额（商户 {{merchant_id}}）：\n单笔下限：{{min}}\n单笔上限：{{max}}\n币种：{{currency}}",
            [
                'merchant_id' => $merchantId,
                'min' => $this->cell($row, 'min'),
                'max' => $this->cell($row, 'max'),
                'currency' => $this->cell($row, 'currency'),
            ]
        ));
    }
}
