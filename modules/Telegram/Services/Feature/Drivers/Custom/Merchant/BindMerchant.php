<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\MerchantRouteService;
use Modules\Telegram\Services\OperatorPolicy;

/**
 * /trbd <商户号> —— 把当前群绑定到商户号
 *
 * 旧实现是 Cache::forever('mid', chat_id)：单机单商户勉强能用，多商户多 bot 下
 * 互相覆盖、清缓存即丢、也无法审计。现在落在 merchant_routes 表：
 *   · 一个商户号一行，唯一约束保证不会被两个群抢绑
 *   · 同时记住「用哪个机器人发、走哪个上游」
 *   · 上游回调来时按商户号反查，多商户多 bot 各走各的
 *
 * 不带参数 = 查看本群当前绑定。
 */
class BindMerchant extends BaseCustomFeature
{
    public static function key(): string
    {
        return 'custom.bind_merchant';
    }

    public static function label(): string
    {
        return '绑定商户号';
    }

    public static function featureName(): string
    {
        return '绑定商户号';
    }

    public static function featureDescription(): string
    {
        return '把当前群绑定到商户号，该商户的交易通知才会发到本群（/trbd <商户号>）';
    }

    public static function category(): string
    {
        return 'bot';
    }

    public static function group(): string
    {
        return '商户';
    }

    public static function commands(): array
    {
        return [
            [
                'command' => 'trbd',
                'usage' => '/trbd <商户号>',
                'description' => '绑定/查看本群的商户号',
                'params' => [
                    ['name' => 'merchant_id', 'required' => false, 'desc' => '商户号，留空表示查看'],
                ],
                'reply_template' => '✅ 本群已绑定商户号：{{merchant_id}}',
            ],
        ];
    }

    public static function defaultConfig(): array
    {
        return [
            'require_admin' => true,
            'allow_overwrite' => true,
            'reply_template' => '✅ 本群已绑定商户号：{{merchant_id}}',
        ];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'require_admin',
                'label' => '仅群管理员可绑定',
                'type' => 'switch',
                'required' => false,
                'default' => true,
            ],
            [
                'key' => 'allow_overwrite',
                'label' => '允许改绑',
                'type' => 'switch',
                'required' => false,
                'default' => true,
                'hint' => '关闭后，已被其它群绑定的商户号不能再绑到本群',
            ],
            [
                'key' => 'reply_template',
                'label' => '回复模板',
                'type' => 'template',
                'required' => false,
                'default' => '✅ 本群已绑定商户号：{{merchant_id}}',
            ],
        ];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $chatId = (string) $context->chatId;
        $policy = app(OperatorPolicy::class);
        $routes = app(MerchantRouteService::class);

        // 绑定关系决定了「通知发到哪、谁能在群里点按钮」，默认只给管理员
        if ($context->config('require_admin', true) && ! $policy->isGroupAdmin($chatId, $context->userId)) {
            return $this->fail('只有群管理员可以绑定商户号');
        }

        $merchantId = trim((string) ($this->value($context, 'merchant_id') ?: $context->arg(0) ?: ''));

        // 不带参数：查看当前绑定
        if ($merchantId === '') {
            $bound = $routes->forChat($chatId);

            return $bound
                ? $this->reply('本群已绑定商户号：' . implode('、', $bound))
                : $this->reply('本群还没有绑定商户号，用法：/trbd <商户号>');
        }

        // 上游：绑定级 > 机器人级 > 功能配置
        $thirdConfigId = $context->bind?->third_config_id
            ?: $context->bot?->third_config_id
            ?: $context->config('upstream_id');

        try {
            $routes->bind(
                (int) ($context->bot?->id ?? 0),
                $chatId,
                $merchantId,
                $thirdConfigId ? (int) $thirdConfigId : null,
                $context->feature->id,
                (bool) $context->config('allow_overwrite', true)
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 同时写一份到功能数据，方便 {{@merchant_id}} 在别的功能里直接引用
        $this->putData($context, ['merchant_id' => $merchantId]);

        return $this->reply($this->render(
            (string) $context->config('reply_template', '✅ 本群已绑定商户号：{{merchant_id}}'),
            ['merchant_id' => $merchantId],
            $context
        ));
    }
}
