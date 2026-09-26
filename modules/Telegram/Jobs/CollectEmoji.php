<?php

namespace Modules\Telegram\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Telegram\Models\Emojis;
use Modules\Telegram\Services\Madeline\MadelineService;
use Modules\Telegram\Services\LogMessageService;

class CollectEmoji implements ShouldQueue
{
    use Queueable;

    public array $entities;
    public string $session_file;

    /**
     * Create a new job instance.
     */
    public function __construct(string $session_file, array $entities)
    {
        $this->entities = $entities;
        $this->session_file = $session_file;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            if (empty($this->entities)) {
                \logger('CollectEmoji: entities is empty');
                return;
            }

            \logger('CollectEmoji: processing ' . count($this->entities) . ' entities');

            $count = 0;
            foreach ($this->entities as $entity) {
                // 检查是否是 CustomEmoji 类型的数据
                if (!isset($entity['type']) || $entity['type'] !== 'custom_emoji') {
                    continue;
                }

                if (!isset($entity['document_id'])) {
                    \logger('CollectEmoji: document_id not found in entity');
                    continue;
                }

                $emojiId = $entity['document_id'];
                \logger('CollectEmoji: processing emoji ' . $emojiId);

                // 保存到数据库
                Emojis::query()->updateOrInsert(
                    ['id' => $emojiId],
                    ['png_path' => null]
                );

                // 下载表情
                $madelineService = app(\Modules\Telegram\Services\User\UserApiFactory::class)->forSession($this->session_file);
                $result = $madelineService->downloadEmojiToPng($emojiId);

                if ($result) {
                    \logger('CollectEmoji: successfully downloaded emoji ' . $emojiId);
                    $count++;
                } else {
                    \logger('CollectEmoji: failed to download emoji ' . $emojiId);
                }
            }

            \logger('CollectEmoji: processed ' . $count . ' emojis');

        } catch (\Exception|\Throwable $e) {
            \logger('CollectEmoji error: ' . $e->getMessage());
            app(LogMessageService::class)->createLaravelLog("telegram_error", [
                'trace' => $e->getTraceAsString(),
            ], 'Collect Emojis Error: ' . $e->getMessage());
            report($e);
        }
    }
}
