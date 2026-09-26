<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Command;

use Modules\Telegram\Contracts\SlashCommand;
use Modules\Telegram\Models\FeaturesBinds;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Modules\Telegram\Services\LogMessageService;

/**
 * 斜杠命令分发器
 *
 * 统一负责：解析命令与参数 → 查后台绑定（开关）→ 校验必填参数与规则 → 执行 → 回复 → 兜底报错。
 * 命令类本身只写业务。
 */
class SlashCommandDispatcher
{
    public function __construct(
        protected readonly SlashCommandRegistry $registry,
        protected readonly LogMessageService $logMessageService,
        protected readonly BotApiFactory $botApiFactory
    ) {}

    /**
     * 分发一条命令消息
     *
     * @return bool 是否命中并处理了命令
     */
    public function dispatch($bot, mixed $update, string $text): bool
    {
        $commandName = $this->parseCommand($text);

        if ($commandName === null) {
            return false;
        }

        $command = $this->registry->get($commandName);

        if (! $command instanceof SlashCommand) {
            return false;
        }

        $chatId = $update['message']['chat']['id'] ?? null;

        if (! $chatId) {
            return false;
        }

        // 后台开关：命令必须在该群被绑定且启用
        if (! $this->isEnabled($bot->id, $chatId, $commandName)) {
            return false;
        }

        $args = $this->parseArgs($text);

        try {
            $telegram = $this->botApiFactory->forBot($bot);
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'slash_command',
                ['bot_id' => $bot->id, 'error' => $e->getMessage()],
                '初始化 Telegram 客户端失败',
                'error'
            );

            return false;
        }

        $context = new CommandContext(
            bot: $bot,
            telegram: $telegram,
            update: $update,
            chatId: $chatId,
            args: $args,
            config: $this->resolveConfig($bot->id, $chatId, $commandName),
            fromId: $update['message']['from']['id'] ?? null,
            rawText: $text,
        );

        // 参数校验（必填 + 正则），不通过直接把用法回给用户
        $error = $this->validate($command, $context);

        if ($error !== null) {
            $context->reply($error);

            return true;
        }

        try {
            $reply = $command->handle($context);

            if ($reply !== '') {
                $context->reply($reply);
            }
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'slash_command',
                [
                    'command' => $commandName,
                    'chat_id' => $chatId,
                    'bot_id' => $bot->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
                '斜杠命令执行失败',
                'error'
            );

            $context->reply('Something went wrong.');
        }

        return true;
    }

    /**
     * 解析命令名（去掉斜杠与 @bot 后缀，转小写）
     */
    protected function parseCommand(string $text): ?string
    {
        $text = trim($text);

        if (! str_starts_with($text, '/')) {
            return null;
        }

        $first = strtok($text, ' ');
        $first = ltrim($first, '/');

        // 群聊里可能是 /ye@MyBot 形式
        if (($pos = strpos($first, '@')) !== false) {
            $first = substr($first, 0, $pos);
        }

        return $first === '' ? null : strtolower($first);
    }

    /**
     * 解析位置参数
     */
    protected function parseArgs(string $text): array
    {
        $parts = preg_split('/\s+/', trim($text));

        array_shift($parts); // 去掉命令本身

        return array_values(array_filter($parts, static fn ($v) => $v !== ''));
    }

    /**
     * 命令是否在该群启用（后台可开关）
     */
    protected function isEnabled(int $botId, int|string $chatId, string $commandName): bool
    {
        return FeaturesBinds::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->where('enabled', 1)
            ->whereHas('features', function ($query) use ($commandName) {
                $query->whereRaw('LOWER(feature) = ?', [strtolower($commandName)])
                    ->where('enabled', 1);
            })
            ->exists();
    }

    /**
     * 取后台为这条命令配置的参数（features.config）
     */
    protected function resolveConfig(int $botId, int|string $chatId, string $commandName): array
    {
        $bind = FeaturesBinds::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->whereHas('features', function ($query) use ($commandName) {
                $query->whereRaw('LOWER(feature) = ?', [strtolower($commandName)]);
            })
            ->with('features')
            ->first();

        // 绑定级别的配置优先，其次功能级别
        $raw = $bind->config ?? $bind?->features?->config ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 校验参数，返回错误信息或 null
     */
    protected function validate(SlashCommand $command, CommandContext $context): ?string
    {
        foreach ($command->params() as $index => $param) {
            $name = $param['name'] ?? $index;
            $required = (bool) ($param['required'] ?? false);
            $rule = $param['rule'] ?? null;

            if (! $context->hasArg($index)) {
                if ($required) {
                    return "Missing parameter: {$name}\nUsage: " . $command->usage();
                }

                continue;
            }

            // 后台配置可覆盖命令类里声明的默认正则：{"rules":{"order_id":"..."}}
            $override = $context->config("rules.{$name}");

            if (is_string($override) && $override !== '') {
                $rule = $override;
            }

            if ($rule && ! preg_match($rule, (string) $context->arg($index))) {
                $message = $param['message'] ?? "Invalid parameter: {$name}";

                return "{$message}\nUsage: " . $command->usage();
            }
        }

        return null;
    }
}
