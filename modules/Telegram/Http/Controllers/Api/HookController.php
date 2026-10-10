<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers\Api;

use Catch\Base\CatchController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Models\FeatureHooks;
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

        // ---- 确定发送目标：功能绑定的第一个机器人 ----
        $bot = Bots::query()->find(
            \Modules\Telegram\Models\FeaturesBinds::query()
                ->where('feature_id', $feature->id)
                ->where('enabled', true)
                ->value('bot_id')
        );

        if (! $bot) {
            return response()->json(['status' => 'error', 'message' => '该功能未绑定任何机器人'], 422);
        }

        $result = $this->executor->execute(
            $feature,
            [
                'trigger' => 'webhook',
                'payload' => $payload,
                'send' => false,
            ],
            $bot,
            $this->botApiFactory->forBot($bot),
            null,
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
}