<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\ApiHookServices;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Telegram\Services\BaseService;

class TradeCallbackService
{
    private const LOG_FILE = 'handle_callback_work';

    public function __construct(
        protected BaseService $baseService,
        protected AiopayService $aiopayService
    ) {}

    /**
     * 处理回调任务
     *
     * @throws \RedisException
     */
    public function handle_callback_work(string $callbackKey, string $message): void
    {
        if (!Redis::exists($callbackKey)) {
            return;
        }

        $redisData = $this->baseService->getRedis($callbackKey);
        $this->baseService->delRedisKey($callbackKey);

        $userId = Cache::get($redisData['chat_id']);
        $chatId = $redisData['chat_id'];
        $messageId = $redisData['message_id'];

        $this->processCallback($message, $userId, $chatId, $messageId);
    }

    /**
     * 处理工作
     */
    public function handle_work(array $params, string $message): void
    {
        [$userId, $chatId, $messageId] = $params;
        $this->processCallback($message, $userId, $chatId, $messageId);
    }

    /**
     * 处理回调逻辑
     */
    private function processCallback(string $message, mixed $userId, mixed $chatId, mixed $messageId): void
    {
        $messageData = json_decode($message, true);

        if (!isset($messageData['out_trade_no'])) {
            $this->logError($chatId, $messageId, 'Missing out_trade_no in message data');
            return;
        }

        $response = $this->aiopayService->getTrade($userId, $messageData['out_trade_no']);

        $text = match (true) {
            $response->successful() => $this->baseService->successful($response->json('data')),
            $response->notFound() => 'result not found',
            default => $this->handleErrorResponse($response, $chatId, $messageId)
        };

        $this->baseService->sendMessage(
            $chatId,
            __('call_back_notify.notify_title1') . "\r\n" . $text,
            $messageId
        );
    }

    /**
     * 处理错误响应
     */
    private function handleErrorResponse($response, mixed $chatId, mixed $messageId): string
    {
        $this->logError($chatId, $messageId, $response->body());
        return 'Something went wrong.';
    }

    /**
     * 记录错误日志
     */
    private function logError(mixed $chatId, mixed $messageId, string $error): void
    {
        $this->baseService->logMessageService->createLaravelLog(
            self::LOG_FILE,
            [
                'chatId' => $chatId,
                'messageId' => $messageId,
                'error' => $error,
            ],
            'Error processing callback',
            'error'
        );
    }
}
