<?php

namespace Modules\Telegram\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Telegram\Services\LogMessageService;
use Modules\Telegram\Services\Madeline\EmojiService;
use Modules\Telegram\Services\Message\MessageRenderer;

/**
 * 采集群消息里的自定义（动态）emoji
 *
 * 只存 emoji_id + 回退字符：emoji_id 是全局 document id，任何 bot 都能引用；
 * 贴纸的 file_id 只对抓到它的账号有效，采了也发不出去，所以不在这里处理。
 */
class CollectEmoji implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $session_file,
        public array $entities,
        public string $text = ''
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            if (empty($this->entities)) {
                return;
            }

            $emojiService = app(EmojiService::class);
            $renderer = app(MessageRenderer::class);

            $count = 0;

            foreach ($this->entities as $entity) {
                if (($entity['type'] ?? '') !== 'custom_emoji') {
                    continue;
                }

                $documentId = $entity['document_id'] ?? null;

                if (! $documentId) {
                    continue;
                }

                // 回退字符：Telegram 的实体必须覆盖一段真实文本，发送时要用它占位
                $alt = $renderer->utf16Substr(
                    $this->text,
                    (int) ($entity['offset'] ?? 0),
                    (int) ($entity['length'] ?? 0)
                );

                $emojiService->storeCustomEmoji((string) $documentId, $alt);

                $count++;
            }

            if ($count > 0) {
                \logger("CollectEmoji: saved {$count} custom emojis");
            }
        } catch (\Exception|\Throwable $e) {
            \logger('CollectEmoji error: ' . $e->getMessage());
            app(LogMessageService::class)->createLaravelLog("telegram_error", [
                'trace' => $e->getTraceAsString(),
            ], 'Collect Emojis Error: ' . $e->getMessage());
            report($e);
        }
    }
}
