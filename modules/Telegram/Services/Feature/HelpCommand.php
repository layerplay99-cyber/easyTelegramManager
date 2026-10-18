<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Models\FeaturesBinds;

/**
 * 固定「帮助」指令
 *
 * 与后台可配的功能命令不同：它是系统内置指令，不写进 feature_commands，
 * 不参与功能的编辑与配置，因此在任何会话（群聊 / 与机器人私聊）里都可用。
 *
 * 作用：把所有「当前会话可用」的命令按统一格式列出来：
 *   指令名称：指令"指令内容+参数描述"，描述
 * 例如：
 *   获取商户余额：指令"/ye"，使用前必须绑定商户id
 *   绑定商户：指令"/bd 商户id"，该群绑定商户，商户id填写后台商户ID值
 */
class HelpCommand
{
    /**
     * 固定指令名（不含斜杠）
     */
    public const COMMAND = 'help';

    /**
     * 兼容旧习惯的别名
     */
    public const ALIASES = ['bz', 'h'];

    /**
     * 是否是帮助指令
     */
    public static function is(string $command): bool
    {
        $command = strtolower(ltrim($command, '/'));

        return $command === self::COMMAND || in_array($command, self::ALIASES, true);
    }

    /**
     * 生成帮助文本
     *
     * 取数规则：
     *   1) 先看该会话绑定的功能（chat_id 命中当前会话，或 chat_id 为空的实体级默认）；
     *   2) 私聊时通常绑不到具体群，此时回退为该机器人绑定的全部功能，
     *      保证「跟机器人私聊也能看到所有指令说明」。
     */
    public function render(Bots $bot, int|string|null $chatId): string
    {
        $featureIds = $this->boundFeatureIds($bot, $chatId);

        if ($featureIds === []) {
            return '当前机器人还没有可用的指令，请先在后台「功能列表」里绑定功能并配置命令。';
        }

        $commands = FeatureCommands::query()
            ->where('enabled', true)
            ->whereIn('feature_id', $featureIds)
            ->with('feature')
            ->orderBy('id')
            ->get()
            ->filter(fn ($c) => $c->feature && $c->feature->enabled);

        if ($commands->isEmpty()) {
            return '当前机器人还没有配置命令，请在后台「功能列表 → 命令」里添加。';
        }

        $lines = ['可用指令：'];

        foreach ($commands as $command) {
            $lines[] = sprintf(
                '%s：指令"%s"，%s',
                $this->name($command),
                $this->usage($command),
                $this->description($command)
            );
        }

        return implode("\n", $lines);
    }

    /**
     * 该会话可用的功能 ID
     *
     * 层级：机器人「配置功能」是白名单，群「配置功能」是白名单里挑子集 ——
     *   这个群自己勾过  → 只列群里启用的
     *   这个群没勾过    → 继承机器人白名单
     *
     * @return array<int, int>
     */
    protected function boundFeatureIds(Bots $bot, int|string|null $chatId): array
    {
        $groupBinds = FeaturesBinds::query()
            ->where('bot_id', $bot->id)
            ->where('chat_id', $chatId ?? FeaturesBinds::ENTITY_WIDE_CHAT_ID);

        // 判断「这个群有没有自己配过」时不能带 enabled 过滤：
        // 群里全关掉也是一种明确的配置，此时应该什么都不列，
        // 而不是因为「启用的查不到」就退回机器人白名单把它又显示出来。
        if ((clone $groupBinds)->exists()) {
            return (clone $groupBinds)
                ->where('enabled', true)
                ->pluck('feature_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return FeaturesBinds::query()
            ->where('bot_id', $bot->id)
            ->where('chat_id', FeaturesBinds::ENTITY_WIDE_CHAT_ID)
            ->where('enabled', true)
            ->pluck('feature_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 指令名称：取功能名
     */
    protected function name(FeatureCommands $command): string
    {
        return (string) ($command->feature->name ?? $command->command);
    }

    /**
     * 指令内容 + 参数描述：优先用后台填的 usage，否则按参数声明拼
     */
    protected function usage(FeatureCommands $command): string
    {
        $usage = trim((string) ($command->usage ?? ''));

        if ($usage !== '') {
            return $usage;
        }

        $text = '/' . $command->command;

        foreach ((array) ($command->params ?? []) as $param) {
            $label = (string) ($param['description'] ?? $param['name'] ?? '');

            if ($label !== '') {
                $text .= ' ' . $label;
            }
        }

        return $text;
    }

    /**
     * 描述：命令描述优先，其次功能描述
     */
    protected function description(FeatureCommands $command): string
    {
        return (string) ($command->description
            ?: $command->feature->description
            ?: '暂无说明');
    }
}
