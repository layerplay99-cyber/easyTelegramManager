<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Modules\Telegram\Contracts\UpdateIntent;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Models\GroupConfigs;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Modules\Telegram\Services\Feature\CommandSlots;

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

        // 固定命令槽位下同名命令可能有多份实现（官方默认 + 各商户定制），
        // 拿全部候选，由商户号路由挑出本群该用的那一份
        $candidates = FeatureCommands::query()
            ->whereRaw('LOWER(command) = ?', [$command])
            ->where('enabled', true)
            ->with('feature')
            ->get()
            ->filter(fn (FeatureCommands $fc) => $fc->feature?->enabled);

        if ($candidates->isEmpty()) {
            return false;
        }

        $featureCommand = $candidates->count() === 1
            ? $candidates->first()
            : $this->resolveForChat($candidates, $chatId);

        if ($featureCommand === null) {
            $this->noImplementationTip($bot, $chatId);

            return true;
        }

        $this->executor->execute(
            $featureCommand->feature,
            [
                'trigger' => 'command',
                'args' => $this->parseArgs($text),
                'params' => is_array($featureCommand->params) ? $featureCommand->params : [],
                'command' => $command,
                'scope_type' => 'group',
                // 槽位命令按群的商户号路由，路由命中即视为绑定（见 FeatureExecutor）：
                // 不再要求后台给每个群单独绑一遍 100 商户 × 11 命令的组合
                'slot_routed' => CommandSlots::is($command),
            ],
            $bot,
            $this->botApiFactory->forBot($bot),
            $chatId,
            $this->fromId($update)
        );

        return true;
    }

    /**
     * 多份实现时按本群商户号路由
     *
     *   群绑定了商户号 → 优先该商户的定制实现（features.merchant_id 匹配）
     *   没有定制 / 没绑商户 → 回落全局默认实现（merchant_id 为空，官方插件）
     *
     * 返回 null = 连默认实现都没有：要么群没绑商户、要么商户没传这个命令
     * 的实现。交给 noImplementationTip() 给可操作的提示。
     */
    protected function resolveForChat($candidates, int|string|null $chatId): ?FeatureCommands
    {
        $merchantId = '';

        try {
            $merchantId = trim((string) (GroupConfigs::merchantIdOf($chatId) ?? ''));
        } catch (\Throwable) {
            // 配置读不出来按「没绑商户」处理，回落默认实现
        }

        if ($merchantId !== '') {
            $matched = $candidates->first(
                fn (FeatureCommands $fc) => trim((string) ($fc->feature->merchant_id ?? '')) === $merchantId
            );

            if ($matched !== null) {
                return $matched;
            }
        }

        return $candidates->first(
            fn (FeatureCommands $fc) => trim((string) ($fc->feature->merchant_id ?? '')) === ''
        );
    }

    /**
     * 路由不到任何实现时的提示
     */
    protected function noImplementationTip(Bots $bot, int|string|null $chatId): void
    {
        $merchantId = '';

        try {
            $merchantId = trim((string) (GroupConfigs::merchantIdOf($chatId) ?? ''));
        } catch (\Throwable) {
        }

        $tip = $merchantId !== ''
            ? "本群商户 {$merchantId} 没有该命令的定制实现，且无默认实现；请让商户上传对应插件"
            : '该命令需要商户实现：请先在群内使用 /bd 绑定商户';

        try {
            $this->botApiFactory->forBot($bot)->sendMessage([
                'chat_id' => $chatId,
                'text' => $tip,
            ]);
        } catch (\Throwable) {
            // 提示发不出去不影响分发结果
        }
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
        return $update?->getMessage()?->chat->id
            ?? $update?->callbackQuery()->message->chat->id
            ?? null;
    }

    private function fromId(mixed $update): ?int
    {
        return $update?->getMessage()?->from->id
            ?? $update?->callbackQuery()->from->id
            ?? null;
    }
}
