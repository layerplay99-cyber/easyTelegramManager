<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Message;

/**
 * 消息渲染器：把结构化 blocks 渲染成 Telegram 能发的东西
 *
 * 为什么不用「纯文本 + {effect_id:x} 魔法串」：
 *  1. 魔法串要二次解析，变量替换后还要重算位置；
 *  2. Telegram 的 offset / length 单位是 UTF-16 code unit，不是字符数，
 *     一个 emoji 占 2 个 unit —— 用 mb_strlen 算必然错位（原来的实现就是这个 bug）。
 *
 * blocks 支持的块：
 *  {"t":"text","v":"亲爱的 "}                       普通文本
 *  {"t":"var","k":"nickname"}                       变量（渲染时替换）
 *  {"t":"emoji","id":"5368...","alt":"🎉"}          自定义/动态 emoji（emoji_id 全局可复用）
 *  {"t":"sticker","file_id":"..."}                  贴纸（file_id 必须属于发送账号）
 *  {"t":"effect","id":"5104841241405538173"}        全屏消息特效（一条消息一个）
 */
class MessageRenderer
{
    /**
     * @param array $blocks
     * @param array $vars 变量表，如 ['nickname' => '张三']
     * @param string $channel bot|user（Bot API 与 MTProto 的实体字段名不同）
     * @return array{text:string,entities:array,stickers:array,effect_id:?string}
     */
    public function render(array $blocks, array $vars = [], string $channel = 'bot'): array
    {
        $text = '';
        $entities = [];
        $stickers = [];
        $effectId = null;

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            switch ((string) ($block['t'] ?? 'text')) {
                case 'text':
                    $text .= $this->replaceVars((string) ($block['v'] ?? ''), $vars);
                    break;

                case 'var':
                    $text .= (string) ($vars[(string) ($block['k'] ?? '')] ?? '');
                    break;

                case 'emoji':
                    // Telegram 要求实体必须覆盖一段真实文本（回退显示用），所以先写 alt 再挂实体。
                    // offset 在拼接的当下算，天然不受后面内容影响。
                    $alt = (string) ($block['alt'] ?? '');
                    if ($alt === '') {
                        break;
                    }

                    $entities[] = $this->customEmojiEntity(
                        $channel,
                        $this->utf16Len($text),
                        $this->utf16Len($alt),
                        (string) ($block['id'] ?? '')
                    );

                    $text .= $alt;
                    break;

                case 'effect':
                    $effectId = (string) ($block['id'] ?? '') ?: null;
                    break;

                case 'sticker':
                    $stickers[] = (string) ($block['file_id'] ?? '');
                    break;
            }
        }

        return [
            'text' => $text,
            'entities' => $entities,
            'stickers' => array_values(array_filter($stickers)),
            'effect_id' => $effectId,
        ];
    }

    /**
     * 自定义 emoji 实体（Bot API 与 MTProto 字段名不同）
     */
    protected function customEmojiEntity(string $channel, int $offset, int $length, string $emojiId): array
    {
        if ($emojiId === '') {
            return [];
        }

        return $channel === 'user'
            ? [
                '_' => 'messageEntityCustomEmoji',
                'offset' => $offset,
                'length' => $length,
                'document_id' => (int) $emojiId,
            ]
            : [
                'type' => 'custom_emoji',
                'offset' => $offset,
                'length' => $length,
                'custom_emoji_id' => $emojiId,
            ];
    }

    /**
     * Telegram 的 offset / length 单位：UTF-16 code unit
     */
    public function utf16Len(string $text): int
    {
        return (int) (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
    }

    /**
     * 采集时按 UTF-16 offset 从原文里截出 alt（回退字符）
     */
    public function utf16Substr(string $text, int $offset, int $length): string
    {
        $u16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $part = substr($u16, $offset * 2, $length * 2);

        return $part === false ? '' : mb_convert_encoding($part, 'UTF-8', 'UTF-16LE');
    }

    /**
     * 变量替换：在拼装阶段完成，不会影响已算好的 offset
     */
    protected function replaceVars(string $text, array $vars): string
    {
        if ($vars === []) {
            return $text;
        }

        return str_replace(
            array_map(fn ($key) => '{' . $key . '}', array_keys($vars)),
            array_values($vars),
            $text
        );
    }
}
