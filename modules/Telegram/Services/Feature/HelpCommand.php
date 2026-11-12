<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Models\Features;
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
        $lines = [];

        // ---- 基础功能：category = base，所有机器人/群默认可用，不占绑定 ----
        $baseIds = Features::query()
            ->where('category', 'base')
            ->where('enabled', true)
            ->pluck('id')
            ->all();

        $baseCommands = FeatureCommands::query()
            ->where('enabled', true)
            ->whereIn('feature_id', $baseIds)
            ->with('feature')
            ->orderBy('id')
            ->get()
            ->filter(fn ($c) => $c->feature && $c->feature->enabled);

        if ($baseCommands->isNotEmpty()) {
            $lines[] = '基础功能：';

            foreach ($baseCommands as $command) {
                $lines[] = sprintf(
                    '%s：指令"%s"，%s',
                    $this->name($command),
                    $this->usage($command),
                    $this->description($command)
                );
            }
        }

        // ---- 商户已配置的功能：本群绑定（群级勾选）的功能 ----
        $boundIds = $this->boundFeatureIds($bot, $chatId);
        $boundIds = array_values(array_diff($boundIds, $baseIds));

        if ($boundIds !== []) {
            $boundCommands = FeatureCommands::query()
                ->where('enabled', true)
                ->whereIn('feature_id', $boundIds)
                ->with('feature')
                ->orderBy('id')
                ->get()
                ->filter(fn ($c) => $c->feature && $c->feature->enabled);

            if ($boundCommands->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '已配置功能：';

                foreach ($boundCommands as $command) {
                    $lines[] = sprintf(
                        '%s：指令"%s"，%s',
                        $this->name($command),
                        $this->usage($command),
                        $this->description($command)
                    );
                }
            }
        }

        if ($lines === []) {
            return '当前没有可用指令。基础功能随功能同步自动生效；商户功能需在后台绑定到本群。';
        }

        return implode("\n", $lines);
    }

    /**
     * 该会话可用的功能 ID
     *
     * 递进关系，和后台「机器人 > 群」一致：机器人配的只是可选范围，
     * 群自己勾过的才列出。群里没勾 → 什么都列不出来。
     *
     * @return array<int, int>
     */
    protected function boundFeatureIds(Bots $bot, int|string|null $chatId): array
    {
        return FeaturesBinds::bindsForSession($bot->id, $chatId)
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
