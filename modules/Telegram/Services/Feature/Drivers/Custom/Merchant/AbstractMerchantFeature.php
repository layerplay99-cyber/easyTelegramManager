<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\Translate\LocalizedReply;

/**
 * 商户类功能公共基类
 *
 * 这一组功能都是「群里发命令 → 拿本群绑定的商户号 → 调上游 → 按群语种回复」。
 * 多语言、商户号解析、单语结果封装全部来自 LocalizedReply trait，
 * 与 BindMerchant / GetMerchantBalance / SetTradeOperator 共用同一份实现。
 * 本基类只留这几个新功能共有的上游配置与调用约定。
 *
 * ⚠️ 本类刻意不 implements EndpointProvider：PlatformEndpointRegistry::providers()
 *    会扫描 Services/ 下所有实现了该接口的类（包括抽象类），接口定义放在各自
 *    用到它的功能类里更清楚，删功能时定义也随之消失。
 *    抽象类也会被两个注册器的 isAbstract() 跳过，不会多出一个空功能。
 */
abstract class AbstractMerchantFeature extends BaseCustomFeature
{
    use LocalizedReply;

    public static function category(): string
    {
        return 'bot';
    }

    public static function group(): string
    {
        return '商户';
    }

    public static function triggerName(): string
    {
        return 'command';
    }

    public static function triggers(): array
    {
        return ['command'];
    }

    protected static function commonDefaultConfig(string $endpointCode, array $extra = []): array
    {
        return array_merge([
            'upstream_id' => null,
            'endpoint_code' => $endpointCode,
            'not_bound_tip' => '',
        ], $extra);
    }

    protected static function commonConfigSchema(string $endpointCode, string $endpointHint = ''): array
    {
        return [
            [
                'key' => 'upstream_id',
                'label' => '承接上游',
                'type' => 'select',
                'source' => 'third_api_configs',
                'required' => false,
                'hint' => '留空则依次用「功能绑定上的上游 → 机器人默认上游」；With Token 取自该上游配置的 token',
            ],
            [
                'key' => 'endpoint_code',
                'label' => '平台接口',
                'type' => 'select',
                'source' => 'platform_endpoints',
                'required' => true,
                'default' => $endpointCode,
                'hint' => $endpointHint !== ''
                    ? $endpointHint
                    : '接口路径与方法在「上游接口配置」里可为每个上游单独覆盖',
            ],
            [
                'key' => 'not_bound_tip',
                'label' => '未绑定商户号时的提示',
                'type' => 'text',
                'required' => false,
                'default' => '',
                'hint' => '留空则按群语种自动翻译；填写则固定使用该文案（不会翻译）。可写 {{bind_usage}} 指定绑定命令位置',
            ],
        ];
    }

    /**
     * 调上游接口：路径占位、With Token、Bearer 头、JSON body 全由 callEndpoint 处理
     */
    protected function callUpstream(FeatureContext $context, string $endpointCode, array $params, string $method): array
    {
        $code = trim((string) $context->config('endpoint_code', ''));

        return $this->callEndpoint(
            $code !== '' ? $code : $endpointCode,
            $params,
            $context,
            $method
        );
    }

    /**
     * 取第一个命令参数作为金额，保留原始字符串（避免 99999.00 被格式化）
     */
    protected function amountArg(FeatureContext $context): ?string
    {
        $raw = trim((string) ($context->arg(0) ?? ''));

        if ($raw === '' || ! is_numeric($raw) || (float) $raw < 0) {
            return null;
        }

        return $raw;
    }
}
