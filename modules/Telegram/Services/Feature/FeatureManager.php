<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Models\FeaturesBinds;
use Modules\Telegram\Models\FeaturesLogs;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Services\LogMessageService;

class FeatureManager
{
    private const CACHE_TTL_MINUTES = 10;
    private const FEATURE_TYPE_COMMAND = 'command';
    private const LOG_FILE = 'feature_errors';

    public function __construct(
        protected LogMessageService $logMessageService
    ) {}

    /**
     * 处理更新
     */
    public function handleUpdate(int $botId, $update): void
    {
        if ($update->has('message') || $update->has('callback_query') || $update->has('inline_query')) {
            $this->handleInteraction($botId, $update);
        }

        if ($update->has('my_chat_member')) {
            $this->handleMyChatMember($update->get('my_chat_member'));
        }

        if ($update->has('chat_member')) {
            $this->handleChatMember($update->get('chat_member'));
        }
    }

    /**
     * 处理交互
     */
    protected function handleInteraction(int $botId, $update): void
    {
        $chatId = $this->extractChatId($update);
        if (!$chatId) {
            return;
        }

        $bindings = $this->getBindings($botId, $chatId);

        foreach ($bindings as $binding) {
            try {
                $feature = $binding->features;
                if (!$feature || !$binding->enabled || !$feature->enabled) {
                    continue;
                }

                $handlerClass = $feature->handler;
                if (!class_exists($handlerClass)) {
                    $this->logMessageService->createLaravelLog(
                        self::LOG_FILE,
                        ['handler' => $handlerClass],
                        "Features handler missing: {$handlerClass}",
                        'warning'
                    );
                    continue;
                }

                $chatModel = BotGroups::firstOrCreate([
                    'bot_id' => $botId,
                    'chat_id' => $chatId
                ]);

                $this->executeFeatureHandler($feature, $handlerClass, $botId, $chatModel, $binding, $update);

            } catch (\Throwable $e) {
                $this->logFeatureError($e, $botId, $chatId, $binding->features_id, $update);
            }
        }
    }

    /**
     * 执行功能处理器
     */
    private function executeFeatureHandler($feature, string $handlerClass, int $botId, $chatModel, $binding, $update): void
    {
        $callback = match ($feature->type) {
            self::FEATURE_TYPE_COMMAND => function() use ($feature, $handlerClass, $botId, $chatModel, $binding, $update) {
                $inputCommand = $update['message']['text'] ?? null;
                if (!$inputCommand) {
                    return;
                }

                $inputCommand = strtok($inputCommand, ' ');
                $config = json_decode($feature->config, true);

                if (!isset($config['command']) || $config['command'] !== $inputCommand) {
                    return;
                }

                $instance = new $handlerClass($botId, $chatModel, $binding->config ?? []);
                if (method_exists($instance, 'handle')) {
                    $instance->handle($update);
                }
            },
            default => function() use ($handlerClass, $botId, $chatModel, $binding, $update) {
                $instance = new $handlerClass($botId, $chatModel, $binding->config ?? []);
                if (method_exists($instance, 'handle')) {
                    $instance->handle($update);
                }
            }
        };

        $callback();
    }

    /**
     * 处理我的聊天成员状态
     */
    protected function handleMyChatMember($chatMember): void
    {
        $chatId = $chatMember->getChat()->getId();
        $newStatus = $chatMember->getNewChatMember()->getStatus();
        info("机器人状态更新: Chat {$chatId}, New Status: {$newStatus}");
    }

    /**
     * 处理聊天成员状态
     */
    protected function handleChatMember($chatMember): void
    {
        $chatId = $chatMember->getChat()->getId();
        $userId = $chatMember->getFrom()->getId();
        $newStatus = $chatMember->getNewChatMember()->getStatus();
        info("群成员状态更新: Chat {$chatId}, User {$userId}, New Status: {$newStatus}");
    }

    /**
     * 提取聊天 ID
     */
    protected function extractChatId($update): ?int
    {
        return $update['message']['chat']['id']
            ?? $update['callback_query']['message']['chat']['id']
            ?? null;
    }

    /**
     * 获取功能绑定
     */
    public function getBindings(int $botId, int|string $chatId)
    {
        $key = $this->cacheKey($botId, $chatId);

        return Cache::remember($key, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($botId, $chatId) {
            return FeaturesBinds::with('features')
                ->where('bot_id', $botId)
                ->where('chat_id', $chatId)
                ->where('enabled', true)
                ->get();
        });
    }

    /**
     * 重新加载缓存
     */
    public function reloadCache(int $botId, int|string $chatId): void
    {
        $key = $this->cacheKey($botId, $chatId);
        Cache::forget($key);
        $this->getBindings($botId, $chatId);
    }

    /**
     * 生成缓存键
     */
    protected function cacheKey(int $botId, int|string $chatId): string
    {
        return "bot:{$botId}:chat:{$chatId}:features";
    }

    /**
     * 记录功能错误
     */
    private function logFeatureError(\Throwable $e, int $botId, int|string $chatId, int $featureId, $update): void
    {
        $this->logMessageService->createLaravelLog(
            self::LOG_FILE,
            [
                'bot_id' => $botId,
                'chat_id' => $chatId,
                'features_id' => $featureId,
                'trace' => $e->getTraceAsString(),
                'update' => $update,
            ],
            "Features handle error: {$e->getMessage()}",
            'error'
        );

        FeaturesLogs::query()->insert([
            'bot_id' => $botId,
            'chat_id' => $chatId,
            'feature_id' => $featureId,
            'level' => 'error',
            'message' => $e->getMessage(),
            'meta' => json_encode(['update' => $update]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
