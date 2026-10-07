<?php

namespace Modules\Telegram\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Telegram\Models\MessageSend;
use Modules\Telegram\Models\MessageSendLog;
use Modules\Telegram\Services\BaseService;
use Modules\Telegram\Services\LogMessageService;

/**
 * 群发消息到单个群
 *
 * 兼容旧用法（type=text|photo + text/photo/caption），
 * 并支持结构化内容渲染出的 entities / stickers / effect_id（自定义 emoji、贴纸、消息特效）。
 */
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
     * Telegram 消息实体（自定义/动态 emoji）——由 MessageRenderer 渲染而来
     */
    public array $entities;

    /**
     * 贴纸 file_id 列表（文本发完后单独 sendSticker）
     */
    public array $stickers;

    /**
     * 全屏消息特效
     */
    public ?string $effectId;

    /**
     * 群发任务 ID（有值时写回执）
     */
    public ?int $sendId;

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
     * 同一 bot 每秒最多投递多少条（Telegram 的限制跨群共享）
     */
    public const DISPATCH_PER_SECOND = 20;

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
    public function __construct(
        ?string $chatId,
        string $type,
        string $botToken,
        string $text = '',
        string $photo = '',
        string $caption = '',
        array $entities = [],
        array $stickers = [],
        ?string $effectId = null,
        ?int $sendId = null
    ) {
        // chat_id 有可能是整型（前端 JSON 传数字），统一转成字符串
        $this->chatId = (string) $chatId;
        $this->type = $type;
        $this->botToken = $botToken;
        $this->text = $text;
        $this->photo = $photo;
        $this->caption = $caption;
        $this->entities = $entities;
        $this->stickers = $stickers;
        $this->effectId = $effectId;
        $this->sendId = $sendId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $baseService = app(BaseService::class);
        $LogMessageService = app(LogMessageService::class);

        $this->throttle();

        try {
            if ($this->type === 'photo') {
                $extra = $this->entities === [] ? [] : ['caption_entities' => $this->entities];

                $result = $baseService->sendPhotoByToken(
                    $this->botToken,
                    $this->chatId,
                    $this->photo,
                    $this->caption,
                    $extra,
                );
            } else {
                $extra = [];

                if ($this->entities !== []) {
                    $extra['entities'] = $this->entities;
                }

                if ($this->effectId) {
                    $extra['message_effect_id'] = $this->effectId;
                }

                $result = $baseService->sendMessageByToken(
                    $this->botToken,
                    $this->chatId,
                    $this->text,
                    $extra,
                );
            }

            if (!$result) {
                throw new \Exception('Send message failed');
            }

            // 贴纸要单独发：一条消息只能有一个媒体
            foreach ($this->stickers as $fileId) {
                $baseService->sendStickerByToken($this->botToken, $this->chatId, $fileId);
            }
        } catch (\Throwable $e) {
            // 这里是「消息没发出去」，可以安全重试：重试不会造成重复消息
            $this->safeRecordResult('failed', $e->getMessage());

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

            return;
        }

        // ------------------------------------------------------------------
        // 走到这里说明消息已经送达 Telegram。此后的任何异常都绝不能重试，
        // 否则同一条消息会被重复发送（历史问题：回执写库失败 -> 判为失败 ->
        // 重试 -> 连发 3 次）。所以收尾动作全部吞掉异常，只记日志。
        // ------------------------------------------------------------------
        $this->safeRecordResult('success');

        $LogMessageService->createLaravelLog('sendGroupMsgSuccess', [
            'chat_id' => $this->chatId,
            'type' => $this->type,
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * 兜底限流：dispatch 时已按序号铺开延迟，但多 worker 并发会叠加，
     * 这里再按任务维度压一层（不 release，避免把 tries 耗光）。
     */
    protected function throttle(): void
    {
        if (! $this->sendId) {
            return;
        }

        if (! RateLimiter::attempt(
            'telegram-broadcast:' . $this->sendId,
            self::DISPATCH_PER_SECOND,
            fn () => true,
            1
        )) {
            sleep(1);
        }
    }

    /**
     * 回执落库：现在只写 log 文件时，后台看不到哪个群失败
     */
    protected function recordResult(string $status, ?string $error = null): void
    {
        if (! $this->sendId) {
            return;
        }

        // 必须用 Eloquent 逐条更新，不能用 Query Builder 的 update()：
        // Query Builder 不做日期转换，now() 会被 PDO 转成 'Y-m-d H:i:s' 字符串，
        // 写入 unsigned int 的 sent_at 列时报 1265 Data truncated。
        MessageSendLog::query()
            ->where('send_id', $this->sendId)
            ->where('chat_id', $this->chatId)
            ->get()
            ->each(function (MessageSendLog $log) use ($status, $error) {
                $log->status = $status;
                $log->error = $error ? mb_substr($error, 0, 500) : null;
                $log->sent_at = now();
                $log->save();
            });

        $send = MessageSend::query()->find($this->sendId);

        if ($send) {
            $status === 'success' ? $send->incrementSuccess() : $send->incrementFailed();
        }
    }

    /**
     * 回执落库（吞掉异常）
     *
     * 回执只是「事后记账」，它失败并不代表消息没发出去。若让它抛出去，
     * 会被 handle() 的 catch 当成发送失败并重试，导致已送达的消息被重复发送。
     * 因此这里所有异常都吞掉并记日志，交由调用方决定是否重试。
     */
    protected function safeRecordResult(string $status, ?string $error = null): void
    {
        try {
            $this->recordResult($status, $error);
        } catch (\Throwable $e) {
            app(LogMessageService::class)->createLaravelLog('sendGroupMsgRecordFail', [
                'chat_id' => $this->chatId,
                'send_id' => $this->sendId,
                'status' => $status,
                'error' => $e->getMessage(),
            ], '回执落库失败（不影响消息是否送达）');
        }
    }

    /**
     * 任务失败处理
     */
    public function failed(\Throwable $exception): void
    {
        $LogMessageService = app(LogMessageService::class);

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
