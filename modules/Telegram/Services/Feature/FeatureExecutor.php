<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Illuminate\Support\Str;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\FeatureLogs;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Models\FeaturesBinds;
use Modules\Telegram\Services\LogMessageService;
use Telegram\Bot\Api;

/**
 * 功能统一执行管线
 *
 * 所有触发入口（斜杠命令 / 按钮 / 回调 / webhook / 手动）都走这一条，
 * 取代原先三套互不一致的匹配规则（features.feature / handler / config.command）。
 *
 * 流程：定位功能 → 校验双开关 → 合并配置（绑定级覆盖 > 功能级）→ 加载已存数据
 *      → 参数校验 → 执行 Driver → 记录日志 → 回复
 */
class FeatureExecutor
{
    public function __construct(protected FeatureDataStore $dataStore)
    {
    }

    /**
     * 按功能 ID 执行
     *
     * @param array<string, mixed> $options args / payload / trigger / scope_type / send
     */
    public function executeById(
        int $featureId,
        array $options = [],
        ?Bots $bot = null,
        ?Api $telegram = null,
        int|string|null $chatId = null,
        ?int $userId = null
    ): FeatureResult {
        $feature = Features::query()->find($featureId);

        if (! $feature) {
            return FeatureResult::fail('功能不存在');
        }

        return $this->execute($feature, $options, $bot, $telegram, $chatId, $userId);
    }

    /**
     * 按功能标识执行（features.feature 代码）
     *
     * 跨驱动调用的入口：一个驱动下的功能可以直接调用另一个驱动下的功能，
     * 标识是稳定的字符串（如 realman.sendToGroups / custom.get_merchant_balance），
     * 比数字 id 更适合写进配置。
     */
    public function callFeature(
        string $featureKey,
        array $options = [],
        ?Bots $bot = null,
        ?Api $telegram = null,
        int|string|null $chatId = null,
        ?int $userId = null
    ): FeatureResult {
        $feature = Features::query()->where('feature', $featureKey)->first();

        if (! $feature) {
            return FeatureResult::fail("功能不存在：{$featureKey}");
        }

        return $this->execute($feature, $options, $bot, $telegram, $chatId, $userId);
    }

    /**
     * 在当前上下文里调用另一个功能（驱动之间互相调用）
     *
     * 自动继承 bot / telegram / chatId / userId / trigger 与 payload，
     * 调用方只需关心「调谁」和「传什么参数」，例如：
     *   真人驱动的「群发」功能 → 调用 command 驱动下的「查询余额」功能
     *
     * @param array<int, string> $args 位置参数
     */
    public function callFrom(
        FeatureContext $context,
        string $featureKey,
        array $args = []
    ): FeatureResult {
        return $this->callFeature(
            $featureKey,
            [
                'trigger' => $context->trigger(),
                'args' => $args,
                'payload' => $context->payload,
            ],
            $context->bot,
            $context->telegram,
            $context->chatId,
            $context->userId
        );
    }

    /**
     * 按功能定义执行
     *
     * @param array<string, mixed> $options
     */
    public function execute(
        Features $feature,
        array $options = [],
        ?Bots $bot = null,
        ?Api $telegram = null,
        int|string|null $chatId = null,
        ?int $userId = null
    ): FeatureResult {
        $startedAt = microtime(true);
        $requestId = (string) Str::uuid();
        $trigger = (string) ($options['trigger'] ?? 'command');

        if (! $feature->enabled) {
            return FeatureResult::fail('功能已停用');
        }

        // 绑定记录：chat_id 为 NULL 的绑定视为「实体级默认」，对任意群生效；
        // chat_id 有值的绑定为「该群覆盖」。
        $bind = null;

        if ($chatId !== null && $bot !== null) {
            $bind = FeaturesBinds::query()
                ->where('bot_id', $bot->id)
                ->where(function ($q) use ($chatId) {
                    $q->where('chat_id', $chatId)->orWhereNull('chat_id');
                })
                ->where('feature_id', $feature->id)
                ->first();

            // command 触发必须存在绑定；webhook / manual 不强制（可能无绑定直接执行）
            if ($trigger === 'command' && ! $bind) {
                return FeatureResult::fail('该功能未绑定到当前会话');
            }
        }

        $config = $this->mergeConfig($feature, $bind);
        $scopeType = (string) ($options['scope_type'] ?? $this->guessScopeType($feature));
        $scopeId = $this->resolveScopeId($scopeType, $chatId, $bot, $userId);

        $stored = ($scopeId !== null && $scopeId !== '')
            ? $this->dataStore->get($feature->id, $scopeType, $scopeId)
            : [];

        $context = new FeatureContext(
            feature: $feature,
            config: $config,
            args: (array) ($options['args'] ?? []),
            params: (array) ($options['params'] ?? []),
            stored: $stored,
            payload: (array) ($options['payload'] ?? []),
            bot: $bot,
            telegram: $telegram,
            chatId: $chatId,
            userId: $userId,
            command: (string) ($options['command'] ?? ''),
            requestId: $requestId,
            bind: $bind,
        );

        $driver = DriverRegistry::resolve((string) $feature->driver);
        if (! $driver) {
            return FeatureResult::fail('未知的执行器：' . $feature->driver);
        }

        $errors = array_merge(
            $driver->validate($config),
            $this->validateParams($context->params, $context->args)
        );

        if ($errors) {
            $result = FeatureResult::fail(implode('；', $errors));
            $this->log($result, $feature, $context, $startedAt);

            return $result;
        }

        try {
            $result = $driver->execute($context);
        } catch (\Throwable $e) {
            $result = FeatureResult::fail('功能执行异常：' . $e->getMessage());
        }

        $this->log($result, $feature, $context, $startedAt);

        $meta = $result->meta;

        // ---- 1) 按钮应答：不调的话用户那边会一直转圈 ----
        if ($telegram && isset($meta['answer_callback']) && is_array($meta['answer_callback'])) {
            $queryId = $options['payload']['callback_query_id'] ?? null;

            if ($queryId) {
                $answer = $meta['answer_callback'];

                try {
                    $telegram->answerCallbackQuery([
                        'callback_query_id' => (string) $queryId,
                        'text' => (string) ($answer['text'] ?? ''),
                        'show_alert' => (bool) ($answer['show_alert'] ?? false),
                    ]);
                } catch (\Throwable) {
                    // 应答失败不影响主流程
                }
            }
        }

        // ---- 2) 编辑触发消息：二次确认、处理完撤掉按钮 ----
        if ($telegram && $chatId !== null && isset($meta['edit_message']) && is_array($meta['edit_message'])) {
            $edit = $meta['edit_message'];
            $targetMessageId = $edit['message_id'] ?? ($options['payload']['message_id'] ?? null);

            if ($targetMessageId) {
                try {
                    if (! empty($edit['remove_markup'])) {
                        $telegram->editMessageReplyMarkup([
                            'chat_id' => $chatId,
                            'message_id' => $targetMessageId,
                            'reply_markup' => ['inline_keyboard' => []],
                        ]);
                    } else {
                        $params = [
                            'chat_id' => $chatId,
                            'message_id' => $targetMessageId,
                            'text' => (string) ($edit['text'] ?? ''),
                        ];

                        if (isset($edit['reply_markup'])) {
                            $params['reply_markup'] = $edit['reply_markup'];
                        }

                        if (! empty($edit['parse_mode'])) {
                            $params['parse_mode'] = $edit['parse_mode'];
                        }

                        $telegram->editMessageText($params);
                    }
                } catch (\Throwable) {
                    // 编辑失败（消息太旧/被删）不影响主流程，仍继续发新消息
                }
            }
        }

        // ---- 3) 发新消息：webhook 场景不回当前会话（由驱动自己指定目标群）----
        if (($options['send'] ?? true)
            && $result->message !== ''
            && $telegram
            && $chatId !== null
            && $trigger !== 'webhook'
        ) {
            $params = ['chat_id' => $chatId, 'text' => $result->message];

            if (isset($meta['reply_markup'])) {
                $params['reply_markup'] = $meta['reply_markup'];
            }

            if (! empty($meta['parse_mode'])) {
                $params['parse_mode'] = $meta['parse_mode'];
            }

            try {
                $telegram->sendMessage($params);
            } catch (\Throwable) {
                // 回复失败不影响功能结果
            }
        }

        return $result;
    }

    /**
     * 命令参数校验：必填 + 正则
     *
     * @param array<int, array<string, mixed>> $params
     * @param array<int, string> $args
     * @return array<int, string>
     */
    protected function validateParams(array $params, array $args): array
    {
        $errors = [];

        foreach ($params as $i => $p) {
            $name = (string) ($p['name'] ?? '第' . ($i + 1) . '个参数');
            $value = $args[$i] ?? null;

            if (($p['required'] ?? false) && ($value === null || $value === '')) {
                $errors[] = "缺少参数：{$name}";
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $pattern = $p['pattern'] ?? null;
            if ($pattern && ! preg_match('#' . $pattern . '#', (string) $value)) {
                $errors[] = "参数格式错误：{$name}";
            }
        }

        return $errors;
    }

    /**
     * 合并配置：绑定级覆盖 > 功能级
     *
     * @return array<string, mixed>
     */
    protected function mergeConfig(Features $feature, ?FeaturesBinds $bind): array
    {
        $base = is_array($feature->config) ? $feature->config : [];

        if (! $bind || ! is_array($bind->config)) {
            return $base;
        }

        return array_merge($base, $bind->config);
    }

    protected function guessScopeType(Features $feature): string
    {
        $config = is_array($feature->config) ? $feature->config : [];

        return (string) ($config['scope_type'] ?? 'group');
    }

    protected function resolveScopeId(
        string $scopeType,
        int|string|null $chatId,
        ?Bots $bot,
        ?int $userId
    ): int|string|null {
        return match ($scopeType) {
            'bot' => $bot?->id,
            'user' => $userId,
            'global' => 'global',
            default => $chatId,
        };
    }

    /**
     * 记录执行日志（失败不影响功能本身）
     */
    protected function log(
        FeatureResult $result,
        Features $feature,
        FeatureContext $context,
        float $startedAt
    ): void {
        try {
            FeatureLogs::query()->create([
                'feature_id' => $feature->id,
                'command' => $context->command ?: null,
                'bot_id' => $context->bot?->id,
                'chat_id' => $context->chatId,
                'user_id' => $context->userId,
                'request_id' => $context->requestId,
                'success' => $result->isSuccess(),
                'level' => $result->isSuccess() ? 'info' : 'error',
                'message' => $result->message !== '' ? mb_substr($result->message, 0, 500) : null,
                'meta' => [
                    'driver' => $feature->driver,
                    'data' => $result->data,
                    'extra' => $result->meta,
                ],
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $e) {
            app(LogMessageService::class)->createLaravelLog('feature_exec', [
                'feature_id' => $feature->id,
                'error' => $e->getMessage(),
            ], '写功能执行日志失败');
        }
    }
}