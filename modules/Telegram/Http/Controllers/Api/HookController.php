<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers\Api;

use Catch\Base\CatchController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\FeatureHookRoutes;
use Modules\Telegram\Models\FeatureHooks;
use Modules\Telegram\Models\FeaturesBinds;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Modules\Telegram\Services\Feature\CallbackSigner;
use Modules\Telegram\Services\Feature\FeatureExecutor;
use Modules\Telegram\Models\Bots;

/**
 * 第三方推送回调入口（第三块功能）
 *
 * 上游只需回调 POST /api/hooks/{token}，即可把数据投递到绑定了本功能的群里。
 * 不再需要「每个业务一个写死路由」——token 关联到 feature，由驱动决定怎么发。
 *
 * 幂等：feature_hooks.dedup_field 配置后，相同字段值的重复推送会被丢弃，
 * 避免上游重试导致群里刷屏。
 */
class HookController extends CatchController
{
    public function __construct(
        protected readonly FeatureExecutor $executor,
        protected readonly BotApiFactory $botApiFactory,
    ) {
    }

    public function handle(Request $request, string $token): JsonResponse
    {
        $hook = FeatureHooks::query()
            ->where('token', $token)
            ->where('enabled', true)
            ->first();

        if (! $hook) {
            return response()->json(['status' => 'error', 'message' => '无效的回调令牌'], 404);
        }

        $feature = $hook->feature;

        if (! $feature || ! $feature->enabled) {
            return response()->json(['status' => 'error', 'message' => '功能不存在或已停用'], 404);
        }

        $payload = $request->all();

        // ---- 验签：配了密钥就必须验，验不过一律丢弃 ----
        // 旧实现只在 env 里放一把全局密钥，谁拿到谁能伪造通知（虽不知密钥，
        // 但重放一条合法通知也能在群里刷屏）。这里：
        //   · 密钥可按 hook 单独配，泄露可单独吊销
        //   · 签名覆盖全部业务字段
        //   · 可选 timestamp + nonce 防重放
        $secret = (string) ($hook->secret ?: config('hook.secret', ''));

        if ($secret !== '') {
            $signField = (string) ($hook->sign_field ?: config('hook.sign_field', 'sign'));
            $algo = (string) ($hook->sign_algo ?: config('hook.sign_algo', 'hmac_sha256'));

            $sign = $request->input($signField);
            $sign = is_scalar($sign) ? (string) $sign : null;

            if (! CallbackSigner::verify($payload, $secret, $algo, $sign)) {
                return response()->json(['status' => 'error', 'message' => '签名校验失败'], 403);
            }

            if ($hook->check_timestamp) {
                $ttl = (int) config('hook.sign_ttl', 300);

                if (! CallbackSigner::timestampFresh($request->input('timestamp'), $ttl)) {
                    return response()->json(['status' => 'error', 'message' => '请求已过期'], 403);
                }

                $nonce = (string) $request->input('nonce', '');

                if ($nonce !== '' && CallbackSigner::nonceSeen((string) $hook->token, $nonce, $ttl * 2)) {
                    return response()->json(['status' => 'success', 'message' => 'duplicate ignored']);
                }
            }
        }

        // ---- 幂等去重：同一值不重复投递 ----
        $dedupField = $hook->dedup_field;

        $dedupKey = null;

        if (! empty($dedupField)) {
            $fingerprint = (string) data_get($payload, $dedupField, '');

            if ($fingerprint !== '') {
                $dedupKey = "hook:dedup:{$hook->id}:{$fingerprint}";

                if (Cache::has($dedupKey)) {
                    // 已处理过，直接返回成功（上游无需重试）
                    return response()->json(['status' => 'success', 'message' => 'duplicate ignored']);
                }
            }
        }

        // ---- 确定发送目标 ----
        // 多机器人下必须同时定下「用哪个机器人发」和「发到哪个群」：
        // 原来取「该功能绑定的第一个机器人」，同一个回调功能绑到多个机器人时，
        // 所有上游回调都会被第一个机器人接走，甚至发到它的群里 —— 这就是串。
        $target = $this->resolveTarget($hook, $payload);

        if (! $target) {
            return response()->json([
                'status' => 'error',
                'message' => $this->targetError ?: '未配置回调目标：请在后台「回调设置」里指定机器人与群',
            ], 422);
        }

        $bot = $target['bot'];
        $chatId = $target['chat_id'];

        $result = $this->executor->execute(
            $feature,
            [
                'trigger' => 'webhook',
                'payload' => $payload,
                'send' => false,
                // 让驱动严格发到路由表算出的群，而不是「功能绑定的所有群」
                'target_chat_id' => $chatId,
            ],
            $bot,
            $this->botApiFactory->forBot($bot),
            $chatId,
            null
        );

        // 只有真正处理成功才占住去重键：失败的话上游会重试，
        // 提前占住会让这笔单在 24h 内的所有重试都被当成重复投递吞掉。
        if ($dedupKey !== null && $result->isSuccess()) {
            Cache::put($dedupKey, true, now()->addDay());
        }

        $hook->last_fired_at = time();
        $hook->save();

        return response()->json([
            'status' => $result->isSuccess() ? 'success' : 'error',
            'message' => $result->message,
        ], $result->isSuccess() ? 200 : 500);
    }

    /**
     * 目标解析失败的原因，回给上游便于排查
     */
    protected string $targetError = '';

    /**
     * 解析本次回调的「机器人 + 群」
     *
     * 优先级：
     *   1. 路由表：报文里 route_field 取到的值（商户ID）→ 机器人 + 群；
     *   2. 回调入口上配的默认机器人 + 默认群；
     *   3. 兜底：群为空时交给驱动按功能配置（target_chat_ids / 绑定群）决定。
     *
     * 任何一步拿不到明确的机器人就直接失败，不再「猜一个」——
     * 猜错就是拿别的机器人的 token 发到别人的群。
     *
     * @return array{bot: Bots, chat_id: int|string|null}|null
     */
    protected function resolveTarget(FeatureHooks $hook, array $payload): ?array
    {
        $route = null;
        $routeValue = $this->routeValue($hook, $payload);

        if ($routeValue !== null && $routeValue !== '') {
            $route = FeatureHookRoutes::query()
                ->where('hook_id', $hook->id)
                ->where('route_value', $routeValue)
                ->where('enabled', true)
                ->first();
        }

        $botId = $route ? (int) $route->bot_id : (int) ($hook->bot_id ?: 0);
        $bot = $botId > 0 ? Bots::query()->find($botId) : null;

        // 明确配了机器人却查不到：不猜，直接失败（配错了就该报错让人看见）
        if ($botId > 0 && (! $bot || empty($bot->api_token))) {
            $this->targetError = '回调配置的机器人不存在或没有 api_token';

            return null;
        }

        // 没配机器人：只有「该功能只绑定了一个机器人」时才可推断，
        // 绑了多个就无从判断该用谁，宁可不发。
        if (! $bot) {
            $bot = $this->guessBot($hook);
        }

        if (! $bot) {
            $this->targetError = '未配置回调机器人，且无法从功能绑定推断（该功能绑定了 0 个或多个机器人）';

            return null;
        }

        $chatId = $route ? (string) $route->chat_id : (string) ($hook->default_chat_id ?: '');

        if ($chatId !== '' && ! $this->chatBelongsToBot((int) $bot->getKey(), $chatId)) {
            $this->targetError = '回调目标群不属于该机器人，已拦截（防止串到别的机器人 / 别的群）';

            logger()->warning('回调目标群与机器人不匹配，已拦截', [
                'hook_id' => $hook->id,
                'bot_id' => $bot->getKey(),
                'chat_id' => $chatId,
                'route_value' => $routeValue,
            ]);

            return null;
        }

        return [
            'bot' => $bot,
            'chat_id' => $chatId !== '' ? $chatId : null,
        ];
    }

    /**
     * 从回调报文里取路由键（商户ID），支持 a.b.c 路径
     */
    protected function routeValue(FeatureHooks $hook, array $payload): ?string
    {
        $field = trim((string) $hook->route_field);

        if ($field === '') {
            return null;
        }

        $value = data_get($payload, $field);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * 旧数据兼容：回调入口没配机器人时，仅当该功能只绑定了一个机器人才可推断
     */
    protected function guessBot(FeatureHooks $hook): ?Bots
    {
        $botIds = FeaturesBinds::query()
            ->where('feature_id', $hook->feature_id)
            ->where('enabled', true)
            ->distinct()
            ->pluck('bot_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (count($botIds) !== 1) {
            return null;
        }

        return Bots::query()->find($botIds[0]);
    }

    /**
     * 目标群是不是这个机器人的群
     *
     * 群里没有这个机器人的记录时（机器人刚进群、还没同步进库）放行；
     * 但只要这个群已经记在别的机器人名下，一律拦截——
     * 那说明这条回调要发的是别人的群，发过去就是串。
     */
    protected function chatBelongsToBot(int $botId, string $chatId): bool
    {
        if ($chatId === '' || ! $botId) {
            return false;
        }

        if (BotGroups::query()->where('bot_id', $botId)->where('chat_id', $chatId)->exists()) {
            return true;
        }

        return ! BotGroups::query()
            ->where('chat_id', $chatId)
            ->where('bot_id', '<>', $botId)
            ->exists();
    }
}