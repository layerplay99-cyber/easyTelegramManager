<?php

namespace Modules\Telegram\Jobs;

use danog\MadelineProto\Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Telegram\Services\Madeline\MadelineService;
use Modules\Telegram\Services\User\UserApiFactory;

class TelegramApiOperateFeatureJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 最大尝试次数（Madeline 会话初始化偶尔失败，需要重试）
     */
    public int $tries = 3;

    /**
     * 单次超时（秒）：会话初始化 + 发消息可能较久
     */
    public int $timeout = 120;

    /**
     * 重试间隔（秒）
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    protected string $sessionFile;
    protected int $appId;
    protected string $appHash;
    protected array $payload;
    protected string $type;
    protected MadelineService $service;
    /**
     * Create a new job instance.
     */
    public function __construct($sessionFile, $appId, $appHash, $payload, $type='text')
    {
        $this->sessionFile = $sessionFile;
        $this->appId = $appId;
        $this->appHash = $appHash;
        $this->payload = $payload;
        $this->type = $type;
    }

    public function handle(): void
    {
        try {
            $sessionFile = $this->sessionFile;
            if (!str_starts_with($sessionFile, 'storage/')) {
                $sessionFile = 'storage/' . $sessionFile;
            }

            $absolutePath = base_path($sessionFile);
            if (!file_exists($absolutePath)) {
                throw new Exception("Session file not found: " . $this->sessionFile . " (checked: " . $absolutePath . ")");
            }

            $this->service = app(UserApiFactory::class)->forSession($sessionFile, $this->appId, $this->appHash);

            $this->type = $this->type ?: 'text';

            match ($this->type) {
                'text' => $this->sendText($this->payload),
                'media' => $this->sendMedia($this->payload),
                'reply' => $this->replyText($this->payload),
                'kick' => $this->kickUser($this->payload),
                default => throw new Exception("Unsupported type: " . $this->type),
            };
        } catch (Exception|\Throwable $e) {
            // 记录到业务日志，方便按 chat_id 定位是哪一群失败了
            app(\Modules\Telegram\Services\LogMessageService::class)->createLaravelLog(
                'telegram_feature_job_fail',
                [
                    'chat_id' => $this->payload['chat_id'] ?? null,
                    'type' => $this->type,
                    'session_file' => $this->sessionFile,
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                ],
                'TelegramApiOperateFeatureJob 执行失败',
                'error'
            );

            report($e);

            // 原来这里 finally { return; } 会把异常吞掉，
            // 队列永远认为任务成功，失败既不重试也不进 failed_jobs。
            // 还有重试次数就抛出去让队列按 backoff 重试。
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    /**
     * 彻底失败后的处理
     */
    public function failed(\Throwable $exception): void
    {
        app(\Modules\Telegram\Services\LogMessageService::class)->createLaravelLog(
            'telegram_feature_job_failed_final',
            [
                'chat_id' => $this->payload['chat_id'] ?? null,
                'type' => $this->type,
                'error' => $exception->getMessage(),
                'attempts' => $this->attempts(),
            ],
            'TelegramApiOperateFeatureJob 最终失败',
            'error'
        );
    }

    private function sendText(array $payload): void
    {
        $this->prepareMentions($payload);
        $this->parseEffects($payload);
        $this->service->sendText(
            $payload['chat_id'],
            $payload['text'] ?? '',
            $payload['buttons'] ?? [],
            $payload['entities'] ?? []
        );
    }

    private function sendMedia(array $payload): void
    {
        $this->prepareMentions($payload);
        $this->parseEffects($payload);

        // 前端 GroupSelectDialog 传的是 mediaPath，这里原来只认 imagePath，导致图片永远为空
        $imagePath = $payload['imagePath'] ?? $payload['mediaPath'] ?? $payload['photo'] ?? '';

        $this->service->sendMedia(
            $payload['chat_id'],
            $imagePath,
            $payload['text'] ?? '',
            $payload['buttons'] ?? [],
            $payload['entities'] ?? []
        );
    }

    private function replyText(array $payload): void
    {
        $this->prepareMentions($payload);
        $this->parseEffects($payload);

        $replyToMsgId = (int) ($payload['reply_to_msg_id'] ?? 0);
        if (!$replyToMsgId) {
            throw new Exception('reply_to_msg_id is required for reply');
        }

        $this->service->sendText(
            $payload['chat_id'],
            $payload['text'] ?? '',
            $payload['buttons'] ?? [],
            $payload['entities'] ?? [],
            ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => $replyToMsgId]
        );
    }

    private function kickUser(array $payload): void
    {
        $userId = $payload['user_id'] ?? null;
        if (!$userId) {
            throw new Exception('user_id is required for kick');
        }

        $this->service->kickUser($payload['chat_id'], $userId);
    }

    /**
     * 在发送前把要 @ 的成员解析成「前缀文本 + messageEntityMentionName 实体」，
     * 拼到消息最前面，并暂存实体，待 parseEffects 之后再合并（避免被表情标记解析覆盖）。
     */
    private function prepareMentions(array &$payload): void
    {
        $mentionIds = $payload['mention_ids'] ?? [];
        if (empty($mentionIds)) {
            return;
        }

        [$prefix, $entities] = $this->service->resolveMentions($mentionIds);
        if ($prefix === '') {
            return;
        }

        $payload['text'] = $prefix . "\n" . ($payload['text'] ?? '');
        $payload['mention_entities'] = $entities;
    }

    private function parseEffects(array &$payload): void
    {
        [$pureText, $entities] = $this->service->parseEffects($payload['text']);
        $payload['text'] = $pureText;
        $mentionEntities = $payload['mention_entities'] ?? [];
        $payload['entities'] = array_merge($mentionEntities, $entities);
    }
}
