<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureDriver;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\Feature\FeatureExecutor;
use Modules\Telegram\Services\Feature\TemplateRenderer;

/**
 * 按钮回调驱动（callback_query）
 *
 * 群里的按钮被点击后，按 callback_data 匹配分支：
 *   - 分支值以 feature: 开头 → 调用另一个功能（跨驱动调用，链路统一）
 *   - 否则按回复模板渲染文本
 *
 * 例：分支 confirm → feature:custom.get_merchant_balance
 *     真人驱动的功能也能被这里调用，反之亦然，全部走 FeatureExecutor。
 */
class CallbackDriver implements FeatureDriver
{
    public function __construct(
        protected FeatureExecutor $executor,
        protected TemplateRenderer $renderer
    ) {
    }

    public static function key(): string
    {
        return 'callback.op';
    }

    public static function label(): string
    {
        return '按钮回调处理';
    }

    public static function group(): string
    {
        return '交互功能';
    }

    public static function triggers(): array
    {
        return ['callback_query'];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'branches',
                'label' => '回调分支',
                'type' => 'keyvalue',
                'required' => true,
                'hint' => '左：callback_data（支持前缀匹配）；右：回复模板，或 feature:功能标识 调用其它功能',
            ],
            [
                'key' => 'default_reply',
                'label' => '未匹配时回复',
                'type' => 'template',
                'required' => false,
                'default' => '未匹配到处理分支',
            ],
        ];
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (empty($config['branches']) || ! is_array($config['branches'])) {
            $errors[] = '必须配置回调分支';
        }

        return $errors;
    }

    public function execute(FeatureContext $context): FeatureResult
    {
        $callbackData = (string) ($context->callbackData() ?? '');

        if ($callbackData === '') {
            return FeatureResult::fail('缺少 callback_data');
        }

        $branches = $context->config('branches', []);

        foreach ($branches as $match => $action) {
            if (! $this->matches((string) $match, $callbackData)) {
                continue;
            }

            $action = (string) $action;

            // 跨驱动调用：feature:功能标识
            if (str_starts_with($action, 'feature:')) {
                $featureKey = trim(substr($action, strlen('feature:')));

                return $this->executor->callFrom($context, $featureKey);
            }

            return FeatureResult::reply(
                $this->renderer->render($action, [], $context->stored),
                ['callback_data' => $callbackData]
            );
        }

        return FeatureResult::reply(
            $this->renderer->render((string) $context->config('default_reply', '未匹配到处理分支'), [], $context->stored),
            ['callback_data' => $callbackData]
        );
    }

    /**
     * 前缀匹配：分支写 confirm 可命中 confirm:123
     */
    private function matches(string $match, string $callbackData): bool
    {
        if ($match === '') {
            return false;
        }

        if (str_ends_with($match, '*')) {
            return str_starts_with($callbackData, rtrim($match, '*'));
        }

        return $match === $callbackData || str_starts_with($callbackData, $match . ':');
    }
}
