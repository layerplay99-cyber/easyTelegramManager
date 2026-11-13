<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\MerchantPay;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\MerchantRouteService;

/**
 * /jb 解绑商户（固定命令槽位）
 *
 * 独立功能：安装后在「功能列表」里是一行，可单独启停 / 绑定 / 配置回复模板。
 * 以 merchant_routes 为准（与 /bd 同一份数据），顺手修掉旧实现把 bot_id
 * 当 merchant_id 传给 unbind() 导致解绑不掉的问题。
 */
class MerchantPayUnbindFeature extends MerchantPayFeature
{
    public static function key(): string
    {
        return 'plugin.merchant_pay.unbind';
    }

    public static function uuid(): string
    {
        return 'merchantPayUnbind';
    }

    public static function label(): string
    {
        return '解绑商户（/jb）';
    }

    public static function featureName(): string
    {
        return '解绑商户（/jb）';
    }

    public static function featureDescription(): string
    {
        return '解绑本群商户号（merchant_routes），带商户代码时校验与当前绑定一致';
    }

    public static function upstreamEndpoints(): array
    {
        return [];
    }

    public static function commands(): array
    {
        return [
            ['command' => 'jb', 'usage' => '/jb 商户代码', 'description' => '解绑本群商户', 'params' => [['name' => 'merchant_id', 'required' => false]]],
        ];
    }

    public static function configSchema(): array
    {
        return array_merge(parent::configSchema(), [
            static::replySchema('已解绑商户：{{merchant_id}}', '可用变量：{{merchant_id}}'),
        ]);
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $chatId = (string) $context->chatId;

        try {
            $routes = app(MerchantRouteService::class)->forChat($chatId);
        } catch (\Throwable) {
            $routes = [];
        }

        if ($routes === []) {
            return $this->fail('该群尚未绑定商户');
        }

        $bound = trim((string) $routes[0]);
        $input = trim((string) ($context->arg(0) ?? ''));

        if ($input !== '' && $input !== $bound) {
            return $this->fail("参数商户与已绑定商户不一致：当前绑定 {$bound}");
        }

        // 一个群可能历史上绑过多个商户号，全部清掉
        foreach ($routes as $merchantId) {
            app(MerchantRouteService::class)->unbind((string) $merchantId, $chatId);
        }

        // 清掉本功能自己的历史数据（旧版 /bd 写在这里）
        $this->putData($context, ['merchant_id' => '']);

        return $this->reply($this->renderReply(
            $context,
            '已解绑商户：{{merchant_id}}',
            ['merchant_id' => $bound]
        ));
    }
}
