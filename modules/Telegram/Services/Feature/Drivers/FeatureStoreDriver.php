<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureDriver;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\Feature\FeatureDataStore;
use Modules\Telegram\Services\Feature\TemplateRenderer;

/**
 * 存数据驱动（绑定类功能）
 *
 * 典型用途：把命令参数存到「群」这个作用域上，供其它功能引用。
 *   /bm 12345 → feature_data(scope=group, chat_id=X) 存 {merchant_id: 12345}
 *   之后「查余额」命令无需再带商户号，URL 模板里写 {{@merchant_id}} 即可。
 *
 * 这样「绑定商户」「绑定客服」「绑定任意配置」全部零代码，只换一组参数名。
 */
class FeatureStoreDriver implements FeatureDriver
{
    public function __construct(protected FeatureDataStore $store)
    {
    }

    public static function key(): string
    {
        return 'feature.store';
    }

    public static function label(): string
    {
        return '绑定/保存数据';
    }

    public static function group(): string
    {
        return '数据绑定';
    }

    public static function triggers(): array
    {
        return ['command', 'manual'];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'scope_type',
                'label' => '存储作用域',
                'type' => 'select',
                'source' => 'scope_types',
                'required' => true,
                'default' => 'group',
                'hint' => 'group=群（chat_id），bot=机器人，user=用户，global=全局',
            ],
            [
                'key' => 'fields',
                'label' => '要保存的字段',
                'type' => 'keyvalue',
                'required' => true,
                'hint' => '左边填字段名（引用时用 {{@字段名}}），右边留空则自动取命令参数同名字段',
            ],
            [
                'key' => 'reply_template',
                'label' => '回复模板',
                'type' => 'template',
                'required' => false,
                'default' => '✅ 已保存：{{@_summary}}',
            ],
            [
                'key' => 'allow_overwrite',
                'label' => '允许覆盖已保存值',
                'type' => 'switch',
                'required' => false,
                'default' => true,
            ],
        ];
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (empty($config['scope_type'])) {
            $errors[] = '必须选择存储作用域';
        }

        if (empty($config['fields']) || ! is_array($config['fields'])) {
            $errors[] = '必须配置要保存的字段';
        }

        return $errors;
    }

    public function execute(FeatureContext $context): FeatureResult
    {
        $scopeType = (string) $context->config('scope_type', 'group');
        $scopeId = $this->resolveScopeId($scopeType, $context);

        if ($scopeId === null || $scopeId === '') {
            return FeatureResult::fail('未能确定存储作用域的标识');
        }

        $fields = $context->config('fields', []);
        $allowOverwrite = (bool) $context->config('allow_overwrite', true);

        if (! $allowOverwrite) {
            $existing = $this->store->get($context->feature->id, $scopeType, $scopeId);
            if (array_intersect_key($existing, $fields)) {
                return FeatureResult::fail('已绑定过，如需覆盖请在后台开启「允许覆盖」');
            }
        }

        // 按字段声明取值：优先命令实参，其次该作用域已有数据
        $data = [];
        foreach (array_keys($fields) as $fieldName) {
            $value = $context->value((string) $fieldName);
            if ($value !== null && $value !== '') {
                $data[(string) $fieldName] = $value;
            }
        }

        if (! $data) {
            return FeatureResult::fail('没有获取到要保存的值，请检查命令参数');
        }

        $this->store->put($context->feature->id, $scopeType, $scopeId, $data);

        $summary = collect($data)
            ->map(fn ($v, $k) => "{$k}={$v}")
            ->implode(', ');

        $template = (string) $context->config('reply_template', '✅ 已保存：{{@_summary}}');
        $message = app(TemplateRenderer::class)->render(
            $template,
            [],
            array_merge($data, ['_summary' => $summary])
        );

        return FeatureResult::reply($message, $data, [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);
    }

    /**
     * 作用域标识：group 用 chat_id，bot 用 bot_id，user 用 user_id
     */
    private function resolveScopeId(string $scopeType, FeatureContext $context): int|string|null
    {
        return match ($scopeType) {
            'bot' => $context->bot?->id,
            'user' => $context->userId,
            'global' => 'global',
            default => $context->chatId,
        };
    }
}