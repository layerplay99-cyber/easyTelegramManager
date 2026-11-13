<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\MerchantRouteService;

/**
 * /bd 绑定商户（固定命令槽位）
 *
 * 独立功能：安装后在「功能列表」里是一行，可单独启停 / 绑定 / 配置回复模板。
 * 校验上游商户存在后写 merchant_routes，供本插件其它 12 个功能共享绑定。
 */
class MerchantPayBindFeature extends MerchantPayFeature
{
    public static function key(): string
    {
        return 'plugin.merchant_pay.bind';
    }

    public static function uuid(): string
    {
        return 'merchantPayBind';
    }

    public static function label(): string
    {
        return '绑定商户（/bd）';
    }

    public static function featureName(): string
    {
        return '绑定商户（/bd）';
    }

    public static function featureDescription(): string
    {
        return '校验上游商户是否存在，绑定本群商户号并写入商户路由；留空查看当前绑定';
    }

    public static function upstreamEndpoints(): array
    {
        return ['pay.check'];
    }

    public static function commands(): array
    {
        return [
            ['command' => 'bd', 'usage' => '/bd 商户代码', 'description' => '绑定商户（校验上游后绑定本群）', 'params' => [['name' => 'merchant_id', 'required' => false, 'hint' => '留空查看当前绑定']]],
        ];
    }

    public static function configSchema(): array
    {
        return array_merge(parent::configSchema(), [
            static::replySchema('已绑定商户：{{merchant_id}}', '可用变量：{{merchant_id}}'),
        ]);
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $merchantId = trim((string) ($context->arg(0) ?? ''));

        if ($merchantId === '') {
            $bound = $this->merchantId($context);

            return $bound === null
                ? $this->fail($this->notBoundTip($context))
                : $this->reply('本群绑定的商户：' . $bound);
        }

        $check = $this->callEndpoint('pay.check', ['merchant_id' => $merchantId], $context);

        if (isset($check['_error'])) {
            return $this->fail('校验商户失败：' . $check['_error']);
        }

        $returned = data_get($check, 'data.id', data_get($check, 'id'));

        if ($returned === null || (string) $returned !== $merchantId) {
            return $this->fail('商户代码不存在：' . $merchantId);
        }

        // 兼容旧数据：本功能自己的 feature_data 也留一份
        $this->putData($context, ['merchant_id' => $merchantId]);

        // 共享绑定：其它命令 / 业务通知 / 审批按钮都从这里读
        if ($context->bot && $context->chatId) {
            app(MerchantRouteService::class)->bind(
                (int) $context->bot->id,
                (string) $context->chatId,
                $merchantId,
                null,
                (int) $context->feature->id
            );
        }

        return $this->reply($this->renderReply(
            $context,
            '已绑定商户：{{merchant_id}}',
            ['merchant_id' => $merchantId]
        ));
    }
}
