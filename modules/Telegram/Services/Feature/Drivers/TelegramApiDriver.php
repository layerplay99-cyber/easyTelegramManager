<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureDriver;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\Feature\TemplateRenderer;

/**
 * Telegram Bot API 驱动（第一块：机器人自身功能）
 *
 * 调用 Bot API 任意方法：发私聊、取群信息、禁言、踢人、设头衔…
 * 后台配置：method（API 方法）+ params（参数，支持占位）+ reply_template（回复渲染）。
 */
class TelegramApiDriver implements FeatureDriver
{
    /** 常用 Bot API 方法（后台下拉，也允许手填其它方法名） */
    public const METHODS = [
        'sendMessage' => '发消息',
        'sendPhoto' => '发图片',
        'sendDocument' => '发文件',
        'forwardMessage' => '转发消息',
        'getChat' => '获取群/频道信息',
        'getChatMember' => '获取群成员信息',
        'getChatAdministrators' => '获取管理员列表',
        'banChatMember' => '封禁群成员',
        'unbanChatMember' => '解封群成员',
        'kickChatMember' => '踢出群成员',
        'promoteChatMember' => '提升管理员',
        'restrictChatMember' => '禁言',
        'leaveChat' => '退出群聊',
        'setChatTitle' => '设置群名',
        'setChatDescription' => '设置群简介',
        'createChatInviteLink' => '创建邀请链接',
        'getFile' => '获取文件信息',
    ];

    public static function key(): string
    {
        return 'telegram.api';
    }

    public static function label(): string
    {
        return '调用 Telegram API';
    }

    public static function group(): string
    {
        return '机器人功能';
    }

    public static function triggers(): array
    {
        return ['command', 'callback_query', 'manual'];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'method',
                'label' => 'API 方法',
                'type' => 'select',
                'source' => 'telegram_methods',
                'required' => true,
            ],
            [
                'key' => 'params',
                'label' => '请求参数',
                'type' => 'keyvalue',
                'required' => true,
                'hint' => '如 chat_id={{@chat_id}}',
            ],
            [
                'key' => 'reply_template',
                'label' => '回复模板',
                'type' => 'template',
                'required' => false,
                'hint' => '如 群名：{{result.title}}；留空则不回复',
            ],
        ];
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (empty($config['method'])) {
            $errors[] = '必须选择 API 方法';
        }

        if (empty($config['params']) || ! is_array($config['params'])) {
            $errors[] = '必须配置请求参数';
        }

        return $errors;
    }

    public function execute(FeatureContext $context): FeatureResult
    {
        $telegram = $context->telegram;
        if (! $telegram) {
            return FeatureResult::fail('未初始化 Telegram 客户端');
        }

        $method = (string) $context->config('method');
        $renderer = app(TemplateRenderer::class);

        $extra = $context->stored;
        $extra['chat_id'] = $extra['chat_id'] ?? $context->chatId;
        $extra['user_id'] = $extra['user_id'] ?? $context->userId;

        foreach ($context->params as $i => $p) {
            $name = $p['name'] ?? null;
            if ($name !== null && isset($context->args[$i])) {
                $extra[$name] = $context->args[$i];
            }
        }

        $params = [];
        foreach ((array) $context->config('params', []) as $key => $value) {
            $params[$key] = is_array($value)
                ? $value
                : $renderer->render((string) $value, [], $extra);
        }

        if ($context->chatId !== null && ! isset($params['chat_id'])) {
            $params['chat_id'] = $context->chatId;
        }

        try {
            $result = $telegram->call($method, $params);
        } catch (\Throwable $e) {
            return FeatureResult::fail('调用 Telegram API 失败：' . $e->getMessage());
        }

        $template = (string) $context->config('reply_template', '');
        if ($template === '') {
            return FeatureResult::ok([], ['method' => $method]);
        }

        $payload = is_object($result)
            ? (json_decode(json_encode($result), true) ?: [])
            : (is_array($result) ? $result : []);

        return FeatureResult::reply(
            $renderer->render($template, $payload, $extra),
            $payload,
            ['method' => $method]
        );
    }
}