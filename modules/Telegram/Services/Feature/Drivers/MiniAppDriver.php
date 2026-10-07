<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers;

/**
 * Mini App 驱动（第四块）：发送 Web App 按钮 + 校验 initData 签名
 *
 * 用法：
 *   mode=keyboard → 聊天底部键盘按钮
 *   mode=inline   → 内联按钮
 *   mode=menu     → 菜单按钮
 */
class MiniAppDriver implements \Modules\Telegram\Contracts\FeatureDriver
{
    public static function key(): string
    {
        return 'miniapp';
    }

    public static function label(): string
    {
        return 'Mini App';
    }

    public static function group(): string
    {
        return 'Mini App';
    }

    public static function triggers(): array
    {
        return ['command', 'manual'];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'web_app_url',
                'label' => 'Web App 地址',
                'type' => 'text',
                'required' => true,
                'hint' => '必须是 HTTPS 域名（Telegram 强制要求）',
            ],
            [
                'key' => 'button_text',
                'label' => '按钮文案',
                'type' => 'text',
                'required' => true,
                'default' => '打开',
            ],
            [
                'key' => 'mode',
                'label' => '打开方式',
                'type' => 'select',
                'source' => 'miniapp_modes',
                'required' => true,
                'default' => 'keyboard',
            ],
            [
                'key' => 'verify_init_data',
                'label' => '校验 initData',
                'type' => 'switch',
                'required' => false,
                'default' => true,
                'hint' => '用 bot token 校验 initData 签名，防伪造身份',
            ],
        ];
    }

    public function validate(array $config): array
    {
        $errors = [];

        $url = (string) ($config['web_app_url'] ?? '');

        if ($url === '') {
            $errors[] = '必须填写 Web App 地址';
        } elseif (! str_starts_with($url, 'https://')) {
            $errors[] = 'Web App 地址必须以 https:// 开头';
        }

        return $errors;
    }

    public function execute(\Modules\Telegram\Contracts\FeatureContext $context): \Modules\Telegram\Contracts\FeatureResult
    {
        $telegram = $context->telegram;
        $chatId = $context->chatId;

        if (! $telegram || $chatId === null) {
            return \Modules\Telegram\Contracts\FeatureResult::fail('缺少会话上下文，无法发送 Mini App');
        }

        $url = (string) $context->config('web_app_url');
        $text = (string) $context->config('button_text', '打开');
        $mode = (string) $context->config('mode', 'keyboard');

        $button = ['text' => $text, 'web_app' => ['url' => $url]];

        try {
            switch ($mode) {
                case 'inline':
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => $text,
                        'reply_markup' => ['inline_keyboard' => [$button]],
                    ]);
                    break;

                case 'menu':
                    $telegram->setChatMenuButton([
                        'chat_id' => $chatId,
                        'menu_button' => ['type' => 'web_app'] + $button,
                    ]);
                    break;

                default:
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => $text,
                        'reply_markup' => ['keyboard' => [$button]],
                    ]);
            }
        } catch (\Throwable $e) {
            return \Modules\Telegram\Contracts\FeatureResult::fail('发送 Mini App 失败：' . $e->getMessage());
        }

        return \Modules\Telegram\Contracts\FeatureResult::ok([], ['mode' => $mode, 'url' => $url]);
    }
}