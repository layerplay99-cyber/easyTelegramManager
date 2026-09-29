<?php

namespace Modules\Telegram\Services\Madeline;

use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\Message\Entities\CustomEmoji;
use Modules\Telegram\Models\Emojis;
use Modules\Telegram\Services\LogMessageService;
use Modules\Telegram\Services\Message\MessageRenderer;

/**
 * 表情包服务 - 表情特效解析、表情包下载与更新
 */
class EmojiService
{
    /**
     * MadelineService 有必填的 $session 构造参数，容器无法解析，
     * 所以放在后面且可空 —— 这样 app(EmojiService::class) 也能拿到实例。
     */
    public function __construct(
        private LogMessageService $logMessageService,
        private ?MadelineService $madelineService = null,
    ) {}

    /**
     * 解析带有文本特效的消息内容
     *
     * @param string $text 包含 {effect_id:数字} 标记的文本
     * @return array{0:string,1:array} [纯文本, 消息实体数组]
     */
    public function parseEffects(string $text): array
    {
        $entities = [];
        $outputText = '';
        $len = mb_strlen($text);
        $i = 0;

        $currentEffects = []; // 存放当前等待分配的 effect_id

        while ($i < $len) {
            // 匹配 {effect_id:(\d+)\}
            if (preg_match('/\{effect_id:(\d+)\}/A', mb_substr($text, $i), $match)) {
                $effectId = intval($match[1]);
                $currentEffects[] = $effectId;
                $i += mb_strlen($match[0]);
            } else {
                $char = mb_substr($text, $i, 1);

                // ⚠️ Telegram 的 offset / length 单位是 UTF-16 code unit，不是字符数。
                // 一个 emoji 占 2 个 unit，原来用 mb_strlen 算会让特效贴到错误的字上。
                $charOffset = $this->utf16Len($outputText);
                $charLength = $this->utf16Len($char);

                $outputText .= $char;

                foreach ($currentEffects as $effectId) {
                    $found = false;
                    for ($j = count($entities) - 1; $j >= 0; $j--) {
                        if ($entities[$j]['effect_id'] === $effectId &&
                            $entities[$j]['offset'] + $entities[$j]['length'] === $charOffset) {
                            $entities[$j]['length'] += $charLength;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $entities[] = [
                            '_' => 'messageEntityTextEffect',
                            'offset' => $charOffset,
                            'length' => $charLength,
                            'effect_id' => $effectId,
                        ];
                    }
                }

                $currentEffects = [];
                $i++;
            }
        }

        return [$outputText, $entities];
    }

    /**
     * 自动更新消息中的自定义表情包
     */
    /**
     * 采集消息里的自定义（动态）emoji 入库
     *
     * 原来这里把 Telegram 的 document_id 直接塞进主键 id，还写了根本不存在的 png_path 列，
     * 结果一条都落不了库。现在按 (type, telegram_id) 去重，顺带把回退字符（alt）存下来 ——
     * 发送时实体必须覆盖 alt 才能在不支持的一端正常显示。
     */
    public function autoUpdateEmojis(Message $msg): int
    {
        if (empty($msg->entities)) {
            return 0;
        }

        $count = 0;

        foreach ($msg->entities as $entity) {
            if (!$entity instanceof CustomEmoji) {
                continue;
            }

            $this->storeCustomEmoji(
                (string) $entity->documentId,
                $this->utf16Substr($msg->message ?? '', $entity->offset, $entity->length)
            );

            $count++;
        }

        return $count;
    }

    /**
     * 落库（按 type + telegram_id 去重）
     */
    public function storeCustomEmoji(string $emojiId, string $alt = ''): void
    {
        if ($emojiId === '') {
            return;
        }

        Emojis::query()->updateOrInsert(
            ['type' => 'custom_emoji', 'telegram_id' => $emojiId],
            [
                'name' => $alt !== '' ? $alt : 'emoji_' . $emojiId,
                'unicode' => $alt !== '' ? $alt : null,
                'source' => 'collected',
            ]
        );
    }

    /**
     * UTF-16 长度（Telegram 的 offset / length 单位）
     */
    protected function utf16Len(string $text): int
    {
        return app(MessageRenderer::class)->utf16Len($text);
    }

    /**
     * 按 UTF-16 offset 截取（取 emoji 的回退字符）
     */
    protected function utf16Substr(string $text, int $offset, int $length): string
    {
        return app(MessageRenderer::class)->utf16Substr($text, $offset, $length);
    }

    /**
     * 取指定 emoji 的回退字符
     */
    public function preview(string $emojiId): ?string
    {
        return Emojis::query()
            ->where('type', 'custom_emoji')
            ->where('telegram_id', $emojiId)
            ->value('unicode');
    }
}
