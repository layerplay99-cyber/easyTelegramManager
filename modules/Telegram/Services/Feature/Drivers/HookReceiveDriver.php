<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureDriver;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Services\Feature\TemplateRenderer;

/**
 * 第三方推送驱动（第三块：三方主动推送 → 发到群里）
 *
 * 上游回调 POST /api/hooks/{token}，由 HookController 定位到本功能后执行。
 * 功能只需配置「发给哪些群」+「消息模板」，无需为每个业务写一个路由。
 *
 * 幂等：feature_hooks.dedup_field 配置后，相同字段值的重复推送会被丢弃，
 * 避免上游重试导致群里刷屏。
 */
class HookReceiveDriver implements FeatureDriver
{
    public static function key(): string
    {
        return 'hook.receive';
    }

    public static function label(): string
    {
        return '接收三方推送';
    }

    public static function group(): string
    {
        return '三方回调';
    }

    public static function triggers(): array
    {
        return ['webhook'];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'target_chat_ids',
                'label' => '推送到哪些群',
                'type' => 'keyvalue',
                'required' => true,
                'hint' => '左边留空=推送到所有已绑定的群；也可以填固定群ID：群ID=>名称',
            ],
            [
                'key' => 'reply_template',
                'label' => '消息模板',
                'type' => 'template',
                'required' => true,
                'default' => '{{_raw}}',
                'hint' => '取回调字段如 {{notify.title}}；{{_raw}} 直接转发原文',
            ],
            [
                'key' => 'parse_mode',
                'label' => '解析模式',
                'type' => 'select',
                'source' => 'parse_modes',
                'required' => false,
                'default' => '',
            ],
            [
                'key' => 'dedup_field',
                'label' => '幂等去重字段',
                'type' => 'text',
                'required' => false,
                'hint' => '如 out_trade_no；上游重试时相同值只发一次',
            ],
        ];
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (empty($config['reply_template'])) {
            $errors[] = '必须配置消息模板';
        }

        return $errors;
    }

    public function execute(FeatureContext $context): FeatureResult
    {
        $renderer = app(TemplateRenderer::class);

        $chatIds = $this->resolveTargetChatIds($context);

        if (! $chatIds) {
            return FeatureResult::fail('未找到推送目标：既未配置固定群ID，也没有已绑定的群');
        }

        $payload = $context->payload;
        $text = $renderer->render(
            (string) $context->config('reply_template', '{{_raw}}'),
            $payload,
            $context->stored
        );

        $parseMode = (string) $context->config('parse_mode', '');
        $extra = $parseMode !== '' ? ['parse_mode' => $parseMode] : [];

        $sent = [];
        $failed = [];

        foreach ($chatIds as $chatId) {
            try {
                $context->telegram?->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $text,
                    ...$extra,
                ]);
                $sent[] = $chatId;
            } catch (\Throwable $e) {
                $failed[] = ['chat_id' => $chatId, 'error' => $e->getMessage()];
            }
        }

        return FeatureResult::ok(
            ['sent' => $sent],
            ['sent_count' => count($sent), 'failed' => $failed]
        );
    }

    /**
     * 目标群：优先用配置的固定群ID，否则推送到该功能已绑定的所有群
     *
     * @return array<int, int|string>
     */
    private function resolveTargetChatIds(FeatureContext $context): array
    {
        $configured = $context->config('target_chat_ids', []);

        if (is_array($configured) && $configured) {
            $ids = [];
            foreach ($configured as $key => $value) {
                // keyvalue 形式：key=群ID value=名称
                $ids[] = $key !== '' && is_numeric($key) ? $key : $value;
            }

            return array_values(array_filter(array_map('trim', array_map('strval', $ids))));
        }

        // 回退：该功能绑定到的群
        $botId = $context->bot?->id;

        return BotGroups::query()
            ->whereIn('chat_id', function ($q) use ($context, $botId) {
                $q->select('chat_id')
                    ->from('features_binds')
                    ->where('feature_id', $context->feature->id)
                    ->where('enabled', true)
                    ->when($botId, fn ($sub) => $sub->where('bot_id', $botId));
            })
            ->pluck('chat_id')
            ->toArray();
    }
}