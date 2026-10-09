<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Modules\Telegram\Contracts\UpdateIntent;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Models\FeaturesBinds;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Modules\Telegram\Services\Feature\Command\FeatureCommandDispatcher;
use Modules\Telegram\Services\Feature\Command\SlashCommandDispatcher;

/**
 * Update 路由器（功能分发的唯一入口，调用链一目了然）
 *
 * 完整链路：
 *
 *   Telegram webhook
 *     └─ HandleTelegramUpdateListener  兜底同步群信息
 *          └─ UpdateRouter::route
 *               ├─ 群成员事件   → ListenBotInGroupService::handleUpdate
 *               ├─ 纯闲聊文本   → 直接丢弃（唯一允许过滤的情况）
 *               ├─ 斜杠命令     → FeatureCommandDispatcher（后台无代码配置优先）
 *               │                → 未命中回退 SlashCommandDispatcher（旧类命令）
 *               └─ 其它全部     → 按 trigger 匹配功能并执行
 *                              （图片/语音/贴纸/文件/位置/按钮/投票… 一律放行）
 *
 * 过滤原则：只有「纯文本消息且不是斜杠命令」才丢弃。
 * 其余任何带内容的 update 都会进入功能分发，功能侧通过
 * features.trigger + config.message_types 自行声明能处理什么。
 */
class UpdateRouter
{
    public function __construct(
        protected UpdateTypeResolver $resolver,
        protected ListenBotInGroupService $botInGroupService,
        protected FeatureCommandDispatcher $featureCommandDispatcher,
        protected SlashCommandDispatcher $legacyCommandDispatcher,
        protected FeatureExecutor $executor,
        protected BotApiFactory $botApiFactory,
        protected LogMessageService $logMessageService,
    ) {
    }

    /**
     * 统一路由入口
     */
    public function route(Bots $bot, mixed $update): void
    {
        $intent = $this->resolver->resolve($update);

        $chatId = $intent->chatId;

        if ($chatId === null) {
            return;
        }

        // ---- 1) 群成员变化：独立处理，不进功能分发 ----
        if ($intent->isMembership()) {
            $this->botInGroupService->handleUpdate($bot, $update);

            return;
        }

        // ---- 2) 纯闲聊文本：唯一允许过滤的情况 ----
        if ($intent->isPlainChat()) {
            return;
        }

        // ---- 3) 斜杠命令：命令分发链路 ----
        if ($intent->isCommand()) {
            $this->routeCommand($bot, $update, $intent);

            return;
        }

        // ---- 4) 其余全部（按钮/图片/语音/贴纸…）：功能分发 ----
        $this->routeToFeatures($bot, $intent);
    }

    /**
     * 斜杠命令链路
     */
    protected function routeCommand(Bots $bot, mixed $update, UpdateIntent $intent): void
    {
        try {
            // 后台无代码配置的功能命令优先
            $handled = $this->featureCommandDispatcher->dispatch(
                $bot,
                $update,
                (string) $intent->text,
                $intent
            );

            if ($handled) {
                return;
            }

            // 未命中则回退到旧的类命令（存量 /bdcs /bm /ye /cx 保持可用）
            $this->legacyCommandDispatcher->dispatch($bot, $update, (string) $intent->text);
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_features',
                [
                    'intent' => $intent->describe(),
                    'text' => $intent->text,
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
     * 功能分发（按钮、图片、语音、贴纸、文件、位置、投票…）
     *
     * 按 features.trigger 匹配，并可用 config.message_types 进一步限定媒体类型。
     */
    protected function routeToFeatures(Bots $bot, UpdateIntent $intent): void
    {
        $chatId = $intent->chatId;

        // 该会话下已绑定的功能：chat_id 有值为群覆盖，NULL 为实体级默认（对任意群生效）
        $raw = FeaturesBinds::query()
            ->with('features')
            ->where('bot_id', $bot->id)
            ->where(function ($q) use ($chatId) {
                $q->where('chat_id', $chatId)->orWhereNull('chat_id');
            })
            ->where('enabled', true)
            ->get();

        // 按 feature_id 去重（群覆盖优先），上游取「群覆盖有则用群覆盖，否则用实体默认」
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

        if ($bindings->isEmpty()) {
            return;
        }

        $telegram = null;

        foreach ($bindings as $binding) {
            $feature = $binding->features;

            if (! $feature || ! $feature->enabled) {
                continue;
            }

            if (! $this->matchesIntent($feature, $intent)) {
                continue;
            }

            try {
                $telegram ??= $this->botApiFactory->forBot($bot);

                $this->executor->execute(
                    $feature,
                    [
                        'trigger' => $intent->type,
                        'scope_type' => 'group',
                        // 完整意图交给功能，识图/按钮等自行取数据
                        'intent_type' => $intent->type,
                        'intent_media_type' => $intent->messageType,
                        'payload' => [
                            '__trigger' => $intent->type,
                            'text' => $intent->text,
                            'callback_data' => $intent->callbackData,
                            'media_type' => $intent->messageType,
                        ],
                    ],
                    $bot,
                    $telegram,
                    $chatId,
                    $intent->userId
                );
            } catch (\Throwable $e) {
                $this->logMessageService->createLaravelLog(
                    'telegram_features',
                    [
                        'intent' => $intent->describe(),
                        'feature_id' => $feature->id,
                        'feature' => $feature->name,
                        'error' => $e->getMessage(),
                    ],
                    '功能执行失败：' . $feature->name,
                    'error'
                );
            }
        }
    }

    /**
     * 功能是否能处理该意图
     *
     * 规则：
     *   1) trigger 必须与意图类型一致（command 走命令链路，这里不会收到 command）
     *   2) 若功能配置了 message_types，则意图的媒体类型必须在其中
     */
    protected function matchesIntent(Features $feature, UpdateIntent $intent): bool
    {
        $trigger = (string) $feature->trigger;

        // trigger 为空视为不参与非命令分发
        if ($trigger === '' || $trigger === 'command' || $trigger === 'manual') {
            return false;
        }

        if ($trigger !== $intent->type) {
            return false;
        }

        // 可选：限定媒体类型
        $config = is_array($feature->config) ? $feature->config : [];
        $allowed = $config['message_types'] ?? null;

        if (is_array($allowed) && $allowed !== []) {
            return in_array((string) $intent->messageType, $allowed, true);
        }

        return true;
    }
}