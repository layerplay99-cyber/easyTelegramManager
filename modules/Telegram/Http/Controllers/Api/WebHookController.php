<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers\Api;

use Catch\Base\CatchController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Telegram\Events\TelegramUpdateReceivedEvent;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Services\LogMessageService;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Modules\Telegram\Services\DistributedLockService;
use Telegram\Bot\Api;

class WebHookController extends CatchController
{
    public function __construct(
        protected readonly Api $telegram,
        protected readonly Bots $bots,
        protected readonly LogMessageService $logMessageService,
        protected readonly DistributedLockService $lockService,
        protected readonly BotApiFactory $botApiFactory,
    ) {}

    /**
     * Telegram Webhook
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $update = $this->telegram->getWebhookUpdate();

            $this->logMessageService->createLaravelLog(
                'telegram_info',
                [
                    'update' => $update,
                    'headers' => $request->headers->all(),
                    'body' => $request->all(),
                ],
                'Received Telegram Webhook',
                'info'
            );

            $bot = $this->bots
                ->where('url_token', $request->header('x-telegram-bot-api-secret-token'))
                ->first();

            if (! $bot) {
                // url_token 对不上（未登记 / 被改过）时继续往下走没有任何意义，
                // 监听器里拿不到 bot 就无法初始化 Api，只能记日志。
                $this->logMessageService->createLaravelLog(
                    'telegram_error',
                    [
                        'secret_token' => $request->header('x-telegram-bot-api-secret-token'),
                        'body' => $request->all(),
                    ],
                    'Webhook 未匹配到机器人，请检查该机器人的 url_token 是否与 setWebhook 时一致',
                    'error'
                );

                return response()->json(['status' => 'ok']);
            }

            $updateId = $update->updateId ?? $update->update_id ?? null;

            // 使用 match 表达式提取 chatId
            $chatId = match(true) {
                $update->getMessage() !== null
                    => $update->getMessage()->chat->id ?? null,
                $update->isType('callback_query') && isset($update->callbackQuery)
                    => $update->callbackQuery->message->chat->id ?? null,
                $update->isType('inline_query') && isset($update->inlineQuery)
                    => $update->inlineQuery->from->id ?? null,
                isset($update->myChatMember)
                    => $update->myChatMember->chat->id ?? null,
                default => null
            };

            $lockKey = $this->lockService->makeTelegramUpdateLockKey($updateId, $chatId, $bot?->id);

            $this->lockService->lock(
                $lockKey,
                fn() => event(new TelegramUpdateReceivedEvent($bot, $update)),
                5,
                fn() => false
            );

        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_error',
                [
                    'trace' => $e->getTraceAsString(),
                    'request' => $request->all()
                ],
                'Telegram Webhook Error: ' . $e->getMessage(),
                'error'
            );
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * 设置 Webhook
     */
    public function setWebhook(int|string $id): JsonResponse
    {
        try {
            $bot = $this->bots->findOrFail($id);

            $telegram = $this->botApiFactory->forBot($bot);

            $result = $telegram->setWebhook([
                'url' => $bot->webhook_url,
                'secret_token' => $bot->url_token,
            ]);

            if (!$result) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to set webhook'
                ], 500);
            }

            $bot->update(['enabled' => true]);

            return response()->json([
                'status' => 'ok',
                'result' => true
            ]);

        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_error',
                ['trace' => $e->getTraceAsString()],
                'Set Telegram Webhook Error: ' . $e->getMessage(),
                'error'
            );

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 删除 Webhook
     */
    public function deleteWebhook(int|string $id): JsonResponse
    {
        try {
            $bot = $this->bots->findOrFail($id);

            $telegram = $this->botApiFactory->forBot($bot);
            $result = $telegram->deleteWebhook();

            $bot->update(['enabled' => false]);

            return response()->json([
                'status' => 'ok',
                'result' => $result
            ]);

        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_error',
                ['trace' => $e->getTraceAsString()],
                'Delete Telegram Webhook Error: ' . $e->getMessage(),
                'error'
            );

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 获取 Webhook 信息（Telegram 端实际注册的回调地址与错误状态）
     *
     * 用于后台「查看状态」：返回 Telegram 实际登记的 url、最近错误、积压更新数等，
     * 比我们库里存的 webhook_url 更权威（setWebhook 成功不代表 URL 真的可达）。
     */
    public function getWebhookInfo(int|string $id): JsonResponse
    {
        try {
            $bot = $this->bots->find($id);

            if (! $bot || empty($bot->api_token)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bot not found or api_token is empty',
                ], 404);
            }

            $telegram = $this->botApiFactory->forBot($bot);
            $info = $telegram->getWebhookInfo();

            return response()->json([
                'status' => 'ok',
                'data' => method_exists($info, 'toArray') ? $info->toArray() : (array) $info,
            ]);
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_error',
                ['trace' => $e->getTraceAsString()],
                'Get Telegram Webhook Info Error: ' . $e->getMessage(),
                'error'
            );

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
