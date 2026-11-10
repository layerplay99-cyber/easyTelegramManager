<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Contracts\UpdateIntent;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Models\FeaturesBinds;
use App\Support\ServiceExpiry;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Modules\Telegram\Services\LogMessageService;
use Modules\User\Models\User as UserModel;

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
 *               ├─ 斜杠命令     → FeatureCommandDispatcher
 *               │                （feature_commands 表 → FeatureExecutor，后台可配）
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
        // （进群退群记录是内部台账，不受商户有效期影响，保持正常收集）
        if ($intent->isMembership()) {
            $this->botInGroupService->handleUpdate($bot, $update);

            return;
        }

        // ---- 1.5) 商户有效期：机器人归属账号过期 → 只回到期提示，全部功能停用 ----
        // 命令 / 按钮 / 图片 / 语音等一切分发都在这条之后，一处拦截覆盖全链路。
        if ($this->creatorExpired($bot)) {
            $this->notifyExpired($bot, $chatId);

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
     * 机器人归属账号是否已过有效期（5 分钟缓存，避免每条 update 都查库）
     *
     * 综合 ServiceExpiry 判定：用户自身或所属商户任一过期即过期；
     * 归属人不存在 / 平台超管视为有效。缓存由 User / Merchant 的 saved
     * 钩子主动清除，后台改完有效期立即生效。
     */
    protected function creatorExpired(Bots $bot): bool
    {
        if (! $bot->creator_id) {
            return false;
        }

        return Cache::remember(
            UserModel::expiryCacheKey((int) $bot->creator_id),
            now()->addMinutes(5),
            function () use ($bot) {
                $user = UserModel::query()->find($bot->creator_id);

                return $user !== null
                    && ! $user->isSuperAdmin()
                    && ServiceExpiry::expiredFor($user);
            }
        );
    }

    /**
     * 到期提示：回一条固定文案后终止本次分发
     */
    protected function notifyExpired(Bots $bot, int|string $chatId): void
    {
        try {
            $this->botApiFactory->forBot($bot)->sendMessage([
                'chat_id' => $chatId,
                'text' => '本机器人归属账号的服务已到期，功能暂停使用，请联系管理员续期。',
            ]);
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_features',
                ['bot_id' => $bot->id, 'chat_id' => $chatId, 'error' => $e->getMessage()],
                '到期提示发送失败',
                'warning'
            );
        }
    }

    /**
     * 斜杠命令链路
     */
    protected function routeCommand(Bots $bot, mixed $update, UpdateIntent $intent): void
    {
        try {
            // 统一走后台配置的命令（feature_commands → FeatureExecutor），不再有 PHP 命令类兜底
            $this->featureCommandDispatcher->dispatch(
                $bot,
                $update,
                (string) $intent->text,
                $intent
            );
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

        // 该会话真正生效的功能：群里只看这个群自己勾的（含「勾了但关掉」）。
        // 以前这里 orWhereNull('chat_id') 把机器人级的也一起捞出来，
        // 于是「机器人配了 = 所有群都能用」，群里怎么勾都不影响实际行为。
        // chat_id 改成 0 之后 orWhereNull 反而一条都匹配不到，
        // 所以这里统一走 FeaturesBinds::bindsForSession，不在别处重复这套规则。
        $raw = FeaturesBinds::bindsForSession($bot->id, $chatId);
        $raw->load('feature');

        // 上游取第一个有值的（会话内同一功能只有一条绑定，这里只是防御性保留）
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

        // 关掉的不参与分发
        $bindings = (new \Illuminate\Database\Eloquent\Collection(array_values($byFeature)))
            ->filter(fn ($b) => (bool) $b->enabled)
            ->values();

        if ($bindings->isEmpty()) {
            return;
        }

        $telegram = null;

        foreach ($bindings as $binding) {
            $feature = $binding->feature;

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
                            // 按钮交互要用：应答这条点击、定位/编辑被点的那条消息
                            'callback_query_id' => $intent->callbackQueryId,
                            'message_id' => $intent->messageId,
                            'username' => $intent->username,
                            'media_type' => $intent->messageType,
                            // 图片/文件等的 file_id，识图这类功能要靠它取回内容
                            'file_id' => $intent->fileId,
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