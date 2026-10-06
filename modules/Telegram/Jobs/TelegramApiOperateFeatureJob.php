<?php

namespace Modules\Telegram\Jobs;

use danog\MadelineProto\Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Telegram\Services\Telethon\TelegramUserApi;
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
    protected TelegramUserApi $service;

    /**
     * 群发任务 ID（有值时写回执，见 MessageSendLog）
     */
    protected ?int $sendId;

    /**
     * Create a new job instance.
     */
    public function __construct($sessionFile, $appId, $appHash, $payload, $type='text', ?int $sendId = null)
    {
        $this->sessionFile = $sessionFile;
        $this->appId = $appId;
        $this->appHash = $appHash;
        $this->payload = $payload;
        $this->type = $type;
        $this->sendId = $sendId;
    }

    public function handle(): void
    {
        try {
            // Telethon 的 session 由 Python 服务持有，PHP 侧不再校验本地文件是否存在
            $this->service = app(UserApiFactory::class)->forSession($this->sessionFile, $this->appId, $this->appHash);

            $this->type = $this->type ?: 'text';

            match ($this->type) {
                'text' => $this->sendText($this->payload),
                'media' => $this->sendMedia($this->payload),
                'reply' => $this->replyText($this->payload),
                'kick' => $this->kickUser($this->payload),
                default => throw new Exception("Unsupported type: " . $this->type),
            };

            $this->recordResult('success');
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
        $this->recordResult('failed', mb_substr($exception->getMessage(), 0, 500));

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

        // 调用方可能已经渲染好实体（自定义 emoji / 文字特效），不能再被覆盖丢掉
        $payload['entities'] = array_merge($payload['entities'] ?? [], $mentionEntities, $entities);
    }

    /**
     * 群发回执：只有传了 sendId 才写
     */
    private function recordResult(string $status, ?string $error = null): void
    {
        if (! $this->sendId) {
            return;
        }

        $chatId = (string) ($this->payload['chat_id'] ?? '');

        \Modules\Telegram\Models\MessageSendLog::query()
            ->where('send_id', $this->sendId)
            ->where('chat_id', $chatId)
            ->update([
                'status' => $status,
                'error' => $error,
                'sent_at' => now(),
            ]);

        $send = \Modules\Telegram\Models\MessageSend::query()->find($this->sendId);

        if ($send) {
            $status === 'success' ? $send->incrementSuccess() : $send->incrementFailed();
        }
    }
}
