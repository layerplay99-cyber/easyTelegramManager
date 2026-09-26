<?php

namespace Modules\Telegram\Services\Madeline;

use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\Message\Entities\CustomEmoji;
use Modules\Telegram\Models\Emojis;
use Modules\Telegram\Services\LogMessageService;

/**
 * 表情包服务 - 表情特效解析、表情包下载与更新
 */
class EmojiService
{
    public function __construct(
        private MadelineService $madelineService,
        private LogMessageService $logMessageService,
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
                $outputText .= $char;
                $charOffset = mb_strlen($outputText) - 1;

                foreach ($currentEffects as $effectId) {
                    $found = false;
                    for ($j = count($entities) - 1; $j >= 0; $j--) {
                        if ($entities[$j]['effect_id'] === $effectId &&
                            $entities[$j]['offset'] + $entities[$j]['length'] === $charOffset) {
                            $entities[$j]['length'] += 1;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $entities[] = [
                            '_' => 'messageEntityTextEffect',
                            'offset' => $charOffset,
                            'length' => 1,
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

            $emojiId = $entity->documentId;

            Emojis::query()->updateOrInsert(
                ['id' => $emojiId],
                ['png_path' => null]
            );

            $this->downloadEmojiToPng($emojiId);

            $count++;
        }

        return $count;
    }

    /**
     * 下载表情包并转换为 PNG
     */
    public function downloadEmojiToPng(int $emojiId): ?string
    {
        try {
            $emojiPath = storage_path('app/public/emojis');
            if (!is_dir($emojiPath)) {
                @mkdir($emojiPath, 0755, true);
            }
            $tgs = $emojiPath . "/$emojiId.tgs";
            $png = $emojiPath . "/$emojiId.png";

            // 下载 TGS
            $this->madelineService->getApi()->downloadToFile(
                ['_' => 'inputDocument', 'id' => $emojiId],
                $tgs
            );

            // 转 PNG（第一帧）
            $cmd = "lottie_convert.py {$tgs} {$png}";
            exec($cmd);

            if (!file_exists($png)) {
                $this->logMessageService->createLaravelLog(
                    'madeline_error',
                    ['emoji_id' => $emojiId],
                    '转换表情包 PNG 失败'
                );
                return null;
            }

            Emojis::query()->where('id', $emojiId)->update([
                'png_path' => "/emojis/$emojiId.png"
            ]);

            return $png;
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'madeline_error',
                ['emoji_id' => $emojiId, 'error' => $e->getMessage()],
                '下载表情包失败: ' . $e->getMessage()
            );
            return null;
        }
    }
}
