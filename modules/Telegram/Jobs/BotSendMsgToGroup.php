<?php

namespace Modules\Telegram\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BotSendMsgToGroup implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public string $chatId;
    public string $type;
    public string $botToken;
    public string $text;
    public string $photo;
    public string $caption;

    /**
     * 任务最大尝试次数
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     * 发送图片时可能要等 Telegram 的 429 retry_after，30s 太短。
     */
    public int $timeout = 90;

    /**
     * 失败重试间隔（秒）：命中 Telegram 限流后立刻重试毫无意义
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(?string $chatId, string $type, string $botToken, string $text = '', string $photo = '', string $caption = '')
    {
        // chat_id 有可能是整型（前端 JSON 传数字），统一转成字符串
        $this->chatId = (string) $chatId;
        $this->type = $type;
        $this->botToken = $botToken;
        $this->text = $text;
        $this->photo = $photo;
        $this->caption = $caption;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $baseService = app(\Modules\Telegram\Services\BaseService::class);
        $LogMessageService = app(\Modules\Telegram\Services\LogMessageService::class);

        try {
            if ($this->type === 'photo') {
                // 发送图片
                $result = $baseService->sendPhotoByToken(
                    $this->botToken,
                    $this->chatId,
                    $this->photo,
                    $this->caption,
                );
            } else {
                // 发送文本消息
                $result = $baseService->sendMessageByToken(
                    $this->botToken,
                    $this->chatId,
                    $this->text,
                );
            }

            if (!$result) {
                throw new \Exception('Send message failed');
            }

            // 记录成功日志
            $LogMessageService->createLaravelLog('sendGroupMsgSuccess', [
                'chat_id' => $this->chatId,
                'type' => $this->type,
                'attempt' => $this->attempts(),
            ]);

        } catch (\Throwable $e) {
            $LogMessageService->createLaravelLog('sendGroupMsgFail', [
                'chat_id' => $this->chatId,
                'type' => $this->type,
                'message' => $this->type === 'photo' ? $this->caption : $this->text,
                'photo' => $this->photo ?? null,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // 如果还有重试次数，则重新抛出异常让队列重试
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    /**
     * 任务失败处理
     */
    public function failed(\Throwable $exception): void
    {
        $LogMessageService = app(\Modules\Telegram\Services\LogMessageService::class);

        $LogMessageService->createLaravelLog('sendGroupMsgFailedFinal', [
            'chat_id' => $this->chatId,
            'type' => $this->type,
            'message' => $this->type === 'photo' ? $this->caption : $this->text,
            'photo' => $this->photo ?? null,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
