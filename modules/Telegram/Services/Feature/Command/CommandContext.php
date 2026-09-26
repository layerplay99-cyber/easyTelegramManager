<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Command;

use Modules\Telegram\Models\Bots;
use Modules\Telegram\Services\LogMessageService;
use Telegram\Bot\Api;

/**
 * 斜杠命令执行上下文
 *
 * 把 bot / telegram 客户端 / update / 参数 / 后台配置 打包传给命令实现，
 * 命令类不再需要自己从 $update 里抠参数、也不再自己 new Api()。
 */
class CommandContext
{
    /**
     * @param Bots $bot 当前机器人
     * @param Api $telegram 已用 bot api_token 初始化的客户端
     * @param mixed $update 原始 update
     * @param int|string $chatId 群/会话 ID
     * @param array $args 位置参数（已去掉命令本身）
     * @param array $config 后台配置（已 json_decode 成数组）
     * @param int|null $fromId 发送者 Telegram ID
     * @param string $rawText 原始消息文本
     */
    public function __construct(
        public readonly Bots $bot,
        public readonly Api $telegram,
        public readonly mixed $update,
        public readonly int|string $chatId,
        public readonly array $args = [],
        public readonly array $config = [],
        public readonly ?int $fromId = null,
        public readonly string $rawText = '',
    ) {}

    /**
     * 取第 $index 个参数（从 0 开始）
     */
    public function arg(int $index, mixed $default = null): mixed
    {
        return $this->args[$index] ?? $default;
    }

    /**
     * 是否提供了第 $index 个参数
     */
    public function hasArg(int $index): bool
    {
        return isset($this->args[$index]) && $this->args[$index] !== '';
    }

    /**
     * 取后台配置项，支持 config.xxx 形式
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * 回复消息到当前会话
     */
    public function reply(string $text): void
    {
        $this->telegram->sendMessage([
            'chat_id' => $this->chatId,
            'text' => $text,
        ]);
    }

    /**
     * 记录命令日志
     */
    public function log(string $message, array $data = [], string $level = 'info'): void
    {
        app(LogMessageService::class)->createLaravelLog(
            'slash_command',
            array_merge([
                'command' => $this->rawText,
                'chat_id' => $this->chatId,
                'bot_id' => $this->bot->id,
            ], $data),
            $message,
            $level
        );
    }
}
