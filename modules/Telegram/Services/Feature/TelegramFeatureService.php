<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Modules\Telegram\Models\FeaturesBinds;
use Modules\Telegram\Models\FeaturesLogs;
use Modules\Telegram\Services\LogMessageService;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

class TelegramFeatureService
{
    private const FEATURE_TYPE_CALLBACK_QUERY = 'callback_query';
    private const LOG_FILE = 'telegram_features';

    public function __construct(
        public TelegramModuleLoader $moduleLoader,
        public LogMessageService $logMessageService,
        public Command\SlashCommandDispatcher $commandDispatcher,
        public Command\FeatureCommandDispatcher $featureCommandDispatcher
    ) {}

    /**
     * 处理交互
     *
     * @throws TelegramSDKException
     */
    public function handleInteraction($bot, $update): void
    {
        $chatId = $this->extractChatId($update);
        if (!$chatId) {
            return;
        }

        $text = $update['message']['text'] ?? null;
        if ($text && str_starts_with($text, '/')) {
            $this->handleCommandInteraction($bot, $update, $chatId, $text);
            return;
        }

        $this->handleNormalInteraction($bot, $update, $chatId);
    }

    /**
     * 处理命令交互
     *
     * 原来这里的两个 bug：
     *   1. new Api($bot->url_token) —— url_token 是 webhook 的 secret token，
     *      不是 bot token，用它初始化客户端所有命令都发不出消息（应为 api_token）。
     *   2. new $handlerClass() —— 不走容器，命令类构造函数里的依赖注入全部失效
     *      （GetBalance/GetOrder 需要 AiopayService，直接 new 会报参数缺失）。
     * 现在统一交给 SlashCommandDispatcher：解析、开关校验、参数校验、执行、兜底都由它负责。
     */
    protected function handleCommandInteraction($bot, $update, int|string $chatId, string $text): void
    {
        try {
            // 无代码命令（feature_commands 表配置）优先，未命中再回退旧的类命令
            if ($this->featureCommandDispatcher->dispatch($bot, $update, $text)) {
                return;
            }

            $this->commandDispatcher->dispatch($bot, $update, $text);
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                self::LOG_FILE,
                [
                    'text' => $text,
                    'chat_id' => $chatId,
                    'bot_id' => $bot->id ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
                '斜杠命令分发失败',
                'error'
            );
        }
    }

    /**
     * 处理普通交互
     */
    protected function handleNormalInteraction($bot, $update, int|string $chatId): void
    {
        $botId = $bot->id;
        $bindings = $this->getBindings($botId, $chatId);
        $modules = $this->moduleLoader->loadModules();

        foreach ($bindings as $binding) {
            try {
                $feature = $binding->features;
                if (!$feature || !$binding->enabled || !$feature->enabled) {
                    continue;
                }

                $handlerClass = $feature->handler;

                if (!class_exists($handlerClass) && !isset($modules[$handlerClass])) {
                    $this->logMessageService->createLaravelLog(
                        self::LOG_FILE,
                        ['update' => $update, 'handler' => $handlerClass],
                        "Handler class {$handlerClass} does not exist.",
                        'warning'
                    );
                    continue;
                }

                // 同样走容器，避免构造函数依赖注入失效
                $instance = $modules[$handlerClass] ?? app($handlerClass);

                match ($feature->type) {
                    self::FEATURE_TYPE_CALLBACK_QUERY => $this->handleCallback($bot, $update, $feature, $instance),
                    default => $instance->handle($bot, $update),
                };

            } catch (\Throwable $e) {
                $this->logFeatureError($e, $botId, $chatId, $binding->features_id, $update);
            }
        }
    }

    /**
     * 处理回调
     */
    protected function handleCallback($bot, $update, $feature, $instance): void
    {
        $config = json_decode($feature->config, true);

        if (method_exists($instance, 'handle')) {
            $instance->handle($bot, $update, $config);
        }
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
    protected function getBindings(int $botId, int|string $chatId): \Illuminate\Database\Eloquent\Collection
    {
        return FeaturesBinds::with('features')
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->get();
    }

    /**
     * 设置绑定功能
     */
    public function setBindFeature($featureBinds, array $validated, int $loginUserId): array
    {
        try {
            $chatId = $validated['chat_id'];
            $botId = $validated['bot_id'] ?? null;
            $featureIds = $validated['feature_ids'] ?? [];

            // 将前端传来的数据转换为 feature_id => enabled 的映射
            $newFeatureMap = collect($featureIds)->keyBy('feature_id')->toArray();
            $newFeatureIdList = array_keys($newFeatureMap);

            // 查询当前群组已有的绑定记录
            $existingBinds = $featureBinds
                ->where('chat_id', $chatId)
                ->get()
                ->keyBy('feature_id');

            // 处理新增和更新
            foreach ($newFeatureMap as $featureId => $item) {
                $featureBinds->updateOrCreate(
                    [
                        'chat_id' => $chatId,
                        'bot_id' => $botId,
                        'feature_id' => $featureId,
                    ],
                    [
                        'enabled' => $item['enabled'] ?? 1,
                        'config' => $item['config'] ?? null,
                        'creator_id' => $loginUserId,
                    ]
                );
            }

            // 删除前端未传来的绑定（表示前端取消了该功能）
            $toDeleteIds = $existingBinds->keys()->diff($newFeatureIdList);
            if ($toDeleteIds->isNotEmpty()) {
                $featureBinds->where('chat_id', $chatId)
                    ->whereIn('feature_id', $toDeleteIds)
                    ->delete();
            }

            return [
                'success' => true,
                'message' => '功能绑定设置成功',
            ];

        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                self::LOG_FILE,
                [
                    'validated' => $validated,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
                'Error setting bind feature',
                'error'
            );

            return [
                'success' => false,
                'message' => '功能绑定设置失败: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 记录功能错误
     */
    private function logFeatureError(\Throwable $e, int $botId, int|string $chatId, int $featureId, array $update): void
    {
        $this->logMessageService->createLaravelLog(
            self::LOG_FILE,
            [
                'update' => $update,
                'trace' => $e->getTraceAsString(),
            ],
            "Error handling feature {$featureId}: {$e->getMessage()}",
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
