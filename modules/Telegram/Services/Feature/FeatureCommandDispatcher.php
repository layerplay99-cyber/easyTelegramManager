<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Modules\Telegram\Contracts\UpdateIntent;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Services\Bot\BotApiFactory;

/**
 * 斜杠命令分发（统一标准链路）
 *
 * 匹配顺序：
 *   1) 固定指令（/help）：系统内置，不入库、不参与后台配置；
 *   2) feature_commands 表（后台「功能列表 → 命令」维护），
 *      命中后交给 FeatureExecutor 按 features.driver 执行。
 *
 * 除固定指令外全系统只有这一条命令分发路径，不再有任何 PHP 命令类兜底，
 * 因此所有业务命令都能后台配置，新增/调整命令无需改代码。
 */
class FeatureCommandDispatcher
{
    public function __construct(
        protected FeatureExecutor $executor,
        protected BotApiFactory $botApiFactory,
        protected HelpCommand $helpCommand,
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
        ?UpdateIntent $intent = null
    ): bool {
        $command = $intent?->commandName() ?? $this->parseName($text);

        if ($command === '') {
            return false;
        }

        $chatId = $intent?->chatId ?? $this->chatId($update);

        if ($chatId === null) {
            return false;
        }

        // ---- 固定指令：帮助（不入库、后台不可配） ----
        if (HelpCommand::is($command)) {
            return $this->sendHelp($bot, $chatId);
        }

        $featureCommand = FeatureCommands::query()
            ->whereRaw('LOWER(command) = ?', [$command])
            ->where('enabled', true)
            ->with('feature')
            ->first();

        if (! $featureCommand?->feature?->enabled) {
            return false;
        }

        $this->executor->execute(
            $featureCommand->feature,
            [
                'trigger' => 'command',
                'args' => $this->parseArgs($text),
                'params' => is_array($featureCommand->params) ? $featureCommand->params : [],
                'command' => $command,
                'scope_type' => 'group',
            ],
            $bot,
            $this->botApiFactory->forBot($bot),
            $chatId,
            $this->fromId($update)
        );

        return true;
    }

    /**
     * 回复帮助说明（固定指令，不写日志、不进功能管线）
     */
    protected function sendHelp(Bots $bot, int|string|null $chatId): bool
    {
        try {
            $this->botApiFactory->forBot($bot)->sendMessage([
                'chat_id' => $chatId,
                'text' => $this->helpCommand->render($bot, $chatId),
            ]);
        } catch (\Throwable) {
            // 回复失败不影响后续分发
            return false;
        }

        return true;
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
