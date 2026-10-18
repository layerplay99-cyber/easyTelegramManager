<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Modules\Telegram\Models\FeaturesBinds;
use Modules\Telegram\Models\FeaturesLogs;
use Illuminate\Support\Facades\Cache;
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
        public FeatureCommandDispatcher $featureCommandDispatcher
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
            // 统一走后台配置的命令（feature_commands → FeatureExecutor）
            $this->featureCommandDispatcher->dispatch($bot, $update, $text);
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

                // 上游：绑定级（(实体,功能)）优先，否则保留实体级默认
                $bindThird = is_object($binding)
                    ? ($binding->third_config_id ?? null)
                    : ($binding['third_config_id'] ?? null);
                if ($bindThird) {
                    $bot->third_config_id = $bindThird;
                }

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
     * 获取功能绑定（缓存优先）
     *
     * 这是每条消息都会走的高频路径（几十个机器人 × 每个几十个群时DB 压力明显）。
     * 绑定关系几乎不变，只有后台改绑定才会变，因此缓存 + 写时失效最合适。
     * 缓存被optimize 清空时自动回源重建。
     */
    protected function getBindings(int $botId, int|string $chatId): \Illuminate\Database\Eloquent\Collection
    {
        $key = "feature:binds:{$botId}:{$chatId}";

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return new \Illuminate\Database\Eloquent\Collection($cached);
        }

        $raw = FeaturesBinds::with('features')
            ->where('bot_id', $botId)
            ->where(function ($q) use ($chatId) {
                // chat_id 为 NULL 的绑定是「实体级默认」，对任意群生效
                $q->where('chat_id', $chatId)->orWhereNull('chat_id');
            })
            ->get();

        // 同一功能可能同时有「群覆盖」(chat_id 有值) 与「实体默认」(chat_id 为 NULL) 两条：
        // 按 feature_id 去重（群覆盖优先），上游取「群覆盖有则用群覆盖，否则用实体默认」。
        $byFeature = [];
        foreach ($raw as $b) {
            $fid = $b->feature_id;
            if (! isset($byFeature[$fid])) {
                $byFeature[$fid] = $b;
                continue;
            }
            $existing = $byFeature[$fid];
            if (empty($existing->third_config_id) && ! empty($b->third_config_id)) {
                $existing->third_config_id = $b->third_config_id;
            }
        }
        $bindings = new \Illuminate\Database\Eloquent\Collection(array_values($byFeature));

        // 只缓存必要字段的数组，避免把完整模型塞进缓存导致体积膨胀/序列化开销
        Cache::put(
            $key,
            $bindings->map(fn ($b) => [
                'id' => $b->id,
                'feature_id' => $b->feature_id,
                'enabled' => $b->enabled,
                'config' => $b->config,
                'third_config_id' => $b->third_config_id,
                'features' => $b->features ? [
                    'id' => $b->features->id,
                    'name' => $b->features->name,
                    'type' => $b->features->type,
                    'handler' => $b->features->handler,
                    'config' => $b->features->config,
                    'enabled' => $b->features->enabled,
                ] : null,
            ])->all(),
            600
        );

        return $bindings;
    }

    /**
     * 设置绑定功能（整存整取）
     *
     * 两级结构：
     *   chat_id = 0            机器人级：给这个机器人分配可用功能（白名单）
     *   chat_id = 具体群 ID    群级：从这个机器人白名单里挑本群能用哪些
     *
     * 返回值必须带 created / updated / deleted 三个数组 ——
     * FeatureBindsController::superStore() 要用它们拼提示语。
     * 这里以前只返回 {success, message}，控制器去 count($results['created'])
     * 直接踩到 PHP8 的「Undefined array key」，于是每次保存都变成 500
     * （数据其实写进去了，前端只看到报错）。
     *
     * @return array{success: bool, message: string, created: array, updated: array, deleted: array}
     */
    public function setBindFeature($featureBinds, array $validated, int $loginUserId): array
    {
        try {
            // 「未指定群」库里存 0，不能存 NULL：唯一索引对 NULL 不生效，会插重
            $chatId = (int) ($validated['chat_id'] ?? 0);
            $botId = (int) ($validated['bot_id'] ?? 0);
            $featureIds = $validated['feature_ids'] ?? [];

            $newFeatureMap = collect($featureIds)->keyBy('feature_id')->toArray();
            $newFeatureIdList = array_map('intval', array_keys($newFeatureMap));

            // 必须带上软删的查：软删行仍然占着 (bot_id, chat_id, feature_id) 唯一索引。
            // 以前不带 withTrashed，于是「勾上 → 取消 → 再勾上」到第三次就插不进去
            // （Duplicate entry），而且这个错只在第二次取消之后才出现，极难复现。
            $existingBinds = $featureBinds->newQuery()
                ->withTrashed()
                ->where('bot_id', $botId)
                ->where('chat_id', $chatId)
                ->get()
                ->keyBy('feature_id');

            $created = [];
            $updated = [];

            foreach ($newFeatureMap as $featureId => $item) {
                $featureId = (int) $featureId;

                // enabled 对外是 bool，库里存 0/1：前端开关给 1/0，
                // 老代码有的地方给 true/false，这里统一成 0/1 再入库
                $enable = $item['enable'] ?? $item['enabled'] ?? true;

                $attributes = [
                    'enabled' => $enable ? 1 : 0,
                    'config' => $item['config'] ?? null,
                    'third_config_id' => $item['third_config_id'] ?? null,
                ];

                $bind = $existingBinds->get($featureId);

                if ($bind) {
                    // 上次被取消过（软删）的先恢复，否则唯一索引上还压着这一条
                    if ($bind->trashed()) {
                        $bind->restore();
                    }

                    // 已存在：改这两级自己的字段，creator_id 保持原值不动
                    $bind->forceFill($attributes)->save();
                    $updated[] = $featureId;

                    continue;
                }

                $featureBinds->create($attributes + [
                    'bot_id' => $botId,
                    'chat_id' => $chatId,
                    'feature_id' => $featureId,
                    'creator_id' => $loginUserId,
                ]);

                $created[] = $featureId;
            }

            // 前端没传的功能 = 这次被取消掉了，删掉本级的绑定
            $toDeleteIds = $existingBinds->keys()
                ->map(fn ($id) => (int) $id)
                ->diff($newFeatureIdList)
                ->values()
                ->all();

            if ($toDeleteIds !== []) {
                $featureBinds->newQuery()
                    ->where('bot_id', $botId)
                    ->where('chat_id', $chatId)
                    ->whereIn('feature_id', $toDeleteIds)
                    ->delete();
            }

            // 绑定关系变了 → 让 getBindings 的缓存失效，否则改了不生效
            //
            // 机器人级（chat_id = 0）的变更会影响这个机器人名下所有群，
            // 而各群的 key 无法枚举，所以把实体级 key 也一起清掉，
            // 并依赖 10 分钟 TTL 兜底。
            if ($botId !== 0) {
                Cache::forget("feature:binds:{$botId}:{$chatId}");

                if ($chatId === 0) {
                    Cache::forget("feature:binds:{$botId}:0");
                }
            }

            return [
                'success' => true,
                'message' => '功能绑定设置成功',
                'created' => $created,
                'updated' => $updated,
                'deleted' => $toDeleteIds,
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
                'created' => [],
                'updated' => [],
                'deleted' => [],
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
