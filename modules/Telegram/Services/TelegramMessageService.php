<?php
declare(strict_types=1);

namespace Modules\Telegram\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Telegram\Enums\TransferOrderType;
use Telegram\Bot\Laravel\Facades\Telegram;

/**
 * Telegram 消息服务 - 消息发送、响应格式化、转账消息构建
 */
class TelegramMessageService
{
    private const LOG_FILE_LARAVEL = 'laravel';

    /**
     * 等 Telegram 的 retry_after 时最多睡多少秒（避免超过 job 的 timeout）
     */
    private const MAX_RETRY_AFTER_SLEEP = 30;

    public function __construct(
        private LogMessageService $logMessageService,
    ) {}

    /**
     * 格式化成功响应内容
     */
    public function successful(array $data): string
    {
        return collect($data)->map(function ($value, $key) {
            return sprintf(
                '%s: %s',
                __(key: $key, locale: 'zh_CN'),
                in_array($key, ['type', 'status'])
                    ? __(key: $value, locale: 'zh_CN') . ' (' . __(key: $value, locale: 'vi') . ')'
                    : $value
            );
        })->join("\n");
    }

    /**
     * 构建转账消息（管理员通知）
     */
    public function buildTransferMessages($payload, $admins): string
    {
        $text = collect([
            'transfer_no' => 'transfers.transfer_no',
            'out_transfer_no' => 'transfers.out_transfer_no',
            'created_at' => 'transfers.created_at',
            'amount' => 'transfers.amount',
            'balance' => 'transfers.balance',
            'currency' => 'transfers.currency',
            'payee_name' => 'transfers.payee_name',
            'payee_account' => 'transfers.payee_account',
        ])->map(function ($key, $field) use ($payload) {
            return __($key, [$field => $payload[$field] ?? '']);
        })->implode("\n");

        return $admins.
            "\n".__('transfers.title1').
            "\n".__('transfers.type').__('transfers.transfer').
            "\n".$text.
            "\n".__('transfers.status').__('transfers.resubmission');
    }

    /**
     * 构建转账待处理消息（管理员通知）
     */
    public function buildTransferPendingMessages($payload, $admins): string
    {
        $text = collect([
            'transfer_no' => 'transfers.transfer_no',
            'out_transfer_no' => 'transfers.out_transfer_no',
            'created_at' => 'transfers.created_at',
            'amount' => 'transfers.amount',
            'bank_name' => 'transfers.bank_name',
            'currency_code' => 'transfers.currency_code',
            'payee_name' => 'transfers.payee_name',
            'payee_account' => 'transfers.payee_account',
        ])->map(function ($key, $field) use ($payload) {
            return __($key, [$field => $payload[$field] ?? '']);
        })->implode("\n");

        $orderType = match ($payload['status']) {
            TransferOrderType::PENDING => __('transfers.pending'),
            TransferOrderType::SUCCESS => __('transfers.success'),
            TransferOrderType::FAILED => __('transfers.failed'),
            default => '',
        };

        $actionType = match ($payload['action'] ?? '') {
            TransferOrderType::LOCKED => __('transfers.lock'),
            TransferOrderType::UNLOCKED => __('transfers.unlock'),
            TransferOrderType::MARK_SUCCESS => __('transfers.mark_success'),
            TransferOrderType::MARK_FAIL => __('transfers.mark_fail'),
            default => '',
        };

        return $admins.
            "\n".__('transfers.title2').
            "\n".__('transfers.type').__('transfers.transfer').
            "\n".$text.
            "\n".__('transfers.status').$orderType.
            "\n".__('transfers.actionStatus').$actionType;
    }

    /**
     * 发送普通消息
     */
    public function sendMessage($chatId, $text, $messageId = null): void
    {
        if ($text) {
            $messageData = [
                'chat_id' => $chatId,
                'text' => $text,
            ];
            if ($messageId) {
                $messageData['reply_to_message_id'] = $messageId;
            }

            Telegram::sendMessage($messageData);
        }
    }

    /**
     * 通过 Bot Token 发送消息
     */
    public function sendMessageByToken(string $botToken, int|string $chatId, string $text, array $extra = []): bool
    {
        return $this->callTelegramApi(
            method: 'sendMessage',
            botToken: $botToken,
            payload: array_merge([
                'chat_id' => $chatId,
                'text'    => $text,
                'parse_mode' => 'HTML',
            ], $extra),
            logContext: [
                'chat_id' => $chatId,
                'text' => $text,
                'extra' => $extra,
            ],
            logTag: 'sendMessageByToken'
        );
    }

    /**
     * 通过 Bot Token 发送图片
     */
    public function sendPhotoByToken(string $botToken, int|string $chatId, string $photo, string $caption = '', array $extra = []): bool
    {
        return $this->callTelegramApi(
            method: 'sendPhoto',
            botToken: $botToken,
            payload: array_merge([
                'chat_id' => $chatId,
                'photo'   => $photo,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ], $extra),
            logContext: [
                'chat_id' => $chatId,
                'photo' => $photo,
                'caption' => $caption,
                'extra' => $extra,
            ],
            logTag: 'sendPhotoByToken'
        );
    }

    /**
     * 调用 Telegram Bot API
     *
     * 关键：Telegram 限流时返回 429 + parameters.retry_after，
     * 必须等够 retry_after 再重试；原来的 retry(2, 100) 只等 100ms，
     * 必然连续失败，最终任务进 failed_jobs（表现就是「只发了几条后面不发」）。
     */
    private function callTelegramApi(
        string $method,
        string $botToken,
        array $payload,
        array $logContext,
        string $logTag
    ): bool {
        $url = "https://api.telegram.org/bot{$botToken}/{$method}";
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::timeout(30)->post($url, $payload);

                if ($response->successful()) {
                    return true;
                }

                $status = $response->status();
                $body = $response->json() ?? [];
                $retryAfter = isset($body['parameters']['retry_after'])
                    ? (int) $body['parameters']['retry_after']
                    : 0;

                // 429 限流：按 Telegram 要求的时间等待后重试
                if ($status === 429 && $retryAfter > 0 && $attempt < $maxAttempts) {
                    sleep(min($retryAfter, self::MAX_RETRY_AFTER_SLEEP));
                    continue;
                }

                // 4xx（除 429）属于永久性错误（机器人被踢、chat 不存在、图片 URL 不可达…），重试无意义
                $this->logMessageService->createLaravelLog(
                    self::LOG_FILE_LARAVEL,
                    $logContext + [
                        'method' => $method,
                        'status' => $status,
                        'response' => $response->body(),
                        'attempt' => $attempt,
                    ],
                    "{$logTag} Telegram API error",
                    'error'
                );

                return false;
            } catch (ConnectionException $e) {
                if ($attempt >= $maxAttempts) {
                    $this->logMessageService->createLaravelLog(
                        self::LOG_FILE_LARAVEL,
                        $logContext + ['method' => $method, 'error' => $e->getMessage()],
                        "{$logTag} ConnectionException",
                        'error'
                    );

                    return false;
                }

                usleep(200000); // 200ms
            } catch (\Throwable $e) {
                $this->logMessageService->createLaravelLog(
                    self::LOG_FILE_LARAVEL,
                    $logContext + ['method' => $method, 'error' => $e->getMessage()],
                    "{$logTag} Exception",
                    'error'
                );

                return false;
            }
        }

        return false;
    }
}
