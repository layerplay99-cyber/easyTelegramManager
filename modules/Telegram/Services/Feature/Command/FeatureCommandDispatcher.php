<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Command;

use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Modules\Telegram\Services\Feature\FeatureExecutor;
use Telegram\Bot\Api;

/**
 * 斜杠命令分发（无代码优先）
 *
 * 匹配顺序：
 *   1) feature_commands 表里配置的命令 → 走 FeatureExecutor（无代码，后台可配）
 *   2) 未命中则回退到旧的 SlashCommand 类（存量 /bdcs /bm /ye /cx 保持可用）
 *
 * 这样后台新建的命令零代码，存量 PHP 命令也不受影响，可逐步迁移。
 */
class FeatureCommandDispatcher
{
    public function __construct(
        protected FeatureExecutor $executor,
        protected BotApiFactory $botApiFactory,
        protected SlashCommandRegistry $registry,
        protected SlashCommandDispatcher $legacy,
    ) {
    }

    /**
     * 分发斜杠命令
     *
     * @param UpdateIntent|null $intent 已解析好的意图（传入可避免重复解析）
     * @return bool 是否已处理该命令
     */
    public function dispatch(
        Bots $bot,
        mixed $update,
        string $text,
        ?\Modules\Telegram\Contracts\UpdateIntent $intent = null
    ): bool {
        $command = $intent?->commandName() ?? $this->parseName($text);

        if ($command === '') {
            return false;
        }

        $chatId = $intent?->chatId ?? $this->chatId($update);

        if ($chatId === null) {
            return false;
        }

        // ---- 1) 后台配置的功能命令（无代码） ----
        $featureCommand = FeatureCommands::query()
            ->whereRaw('LOWER(command) = ?', [$command])
            ->where('enabled', true)
            ->with('feature')
            ->first();

        if ($featureCommand && $featureCommand->feature && $featureCommand->feature->enabled) {
            $telegram = $this->botApiFactory->forBot($bot);

            $result = $this->executor->execute(
                $featureCommand->feature,
                [
                    'trigger' => 'command',
                    'args' => $this->parseArgs($text),
                    'params' => is_array($featureCommand->params) ? $featureCommand->params : [],
                    'command' => $command,
                    'scope_type' => 'group',
                ],
                $bot,
                $telegram,
                $chatId,
                $this->fromId($update)
            );

            // 驱动没给出文本时，用命令自带的回复模板兜底
            if ($result->message !== '' && ! $result->isSuccess()) {
                return true;
            }

            return true;
        }

        // ---- 2) 回退旧的类命令 ----
        return $this->legacy->dispatch($bot, $update, $text);
    }

    /**
     * 提取命令名（小写、无斜杠、去掉 @botname）
     */
    private function parseName(string $text): string
    {
        $text = trim($text);

        if (! str_starts_with($text, '/')) {
            return '';
        }

        // 支持 /cmd@botname 形式
        $parts = preg_split('/[\s@]+/', $text);

        return strtolower(ltrim((string) ($parts[0] ?? ''), '/'));
    }

    /**
     * 提取位置参数
     *
     * @return array<int, string>
     */
    private function parseArgs(string $text): array
    {
        $parts = preg_split('/\s+/', trim($text));

        // 去掉命令本身
        array_shift($parts);

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    private function chatId(mixed $update): int|string|null
    {
        return $update->getMessage()?->chat->id
            ?? $update->callbackQuery()->message->chat->id
            ?? null;
    }

    private function fromId(mixed $update): ?int
    {
        return $update->getMessage()?->from->id
            ?? $update->callbackQuery()->from->id
            ?? null;
    }
}