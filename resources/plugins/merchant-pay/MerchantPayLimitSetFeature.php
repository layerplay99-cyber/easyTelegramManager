<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;

/**
 * /xe 设置代付审批限额（固定命令槽位）
 *
 * 独立功能：安装后在「功能列表」里是一行，可单独启停 / 绑定 / 配置回复模板。
 */
class MerchantPayLimitSetFeature extends MerchantPayFeature
{
    public static function key(): string
    {
        return 'plugin.merchant_pay.limit_set';
    }

    public static function uuid(): string
    {
        return 'merchantPayLimitSet';
    }

    public static function label(): string
    {
        return '设置代付审批限额（/xe）';
    }

    public static function featureName(): string
    {
        return '设置代付审批限额（/xe）';
    }

    public static function featureDescription(): string
    {
        return '设置单笔代付审批的下限与上限（下限不得大于上限）';
    }

    public static function upstreamEndpoints(): array
    {
        return ['pay.limit.set'];
    }

    public static function commands(): array
    {
        return [
            ['command' => 'xe', 'usage' => '/xe 金额1 金额2', 'description' => '设置代付审批限额', 'params' => [['name' => 'min', 'required' => true], ['name' => 'max', 'required' => true]]],
        ];
    }

    public static function configSchema(): array
    {
        return array_merge(parent::configSchema(), [
            static::replySchema('代付审批限额已设置：{{min}} ~ {{max}}', '可用变量：{{min}} {{max}} {{merchant_id}}'),
        ]);
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $merchantId = $this->merchantId($context);

        if ($merchantId === null) {
            return $this->fail($this->notBoundTip($context));
        }

        $min = $this->normalizeAmount((string) ($context->arg(0) ?? ''));
        $max = $this->normalizeAmount((string) ($context->arg(1) ?? ''));

        if ($min === null || $max === null) {
            return $this->fail('用法：/xe 金额1 金额2（单笔下限、单笔上限）');
        }

        if (bccomp($min, $max, 8) > 0) {
            return $this->fail('下限不能大于上限');
        }

        $data = $this->callEndpoint('pay.limit.set', [
            'merchant_id' => $merchantId,
            'min' => $min,
            'max' => $max,
        ], $context, 'POST');

        if (isset($data['_error'])) {
            return $this->fail('设置失败：' . $data['_error']);
        }

        if (! $this->isSuccess($data)) {
            return $this->fail('设置失败：上游返回未成功');
        }

        return $this->reply($this->renderReply(
            $context,
            '代付审批限额已设置：{{min}} ~ {{max}}',
            ['min' => $min, 'max' => $max, 'merchant_id' => $merchantId]
        ));
    }
}
