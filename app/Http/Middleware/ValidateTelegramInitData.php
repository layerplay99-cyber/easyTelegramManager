<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 校验 Telegram Mini App 的 initData
 *
 * Mini App 打开时会把 initData 带给 H5，H5 再原样回传后端。
 * 这里按 Telegram 官方规则校验：
 *   1. 去掉 hash 字段，其余按 key 字典序拼接成 data_check_string；
 *   2. secret_key = HMAC_SHA256(bot_token, "WebAppData")；
 *   3. hash = HMAC_SHA256(data_check_string, secret_key)，用 hash_equals 比对；
 *   4. auth_date 必须在有效期内（默认 1 小时），防旧数据重放。
 *
 * 校验通过后把 telegram 用户 ID 写进请求属性（telegram_user_id），
 * 业务层一律以它为准 —— 不接受客户端自己传 member_id / user_id，
 * 从根上杜绝「伪造成别人来充值提现」。
 */
class ValidateTelegramInitData
{
    public function handle(Request $request, Closure $next, ?string $botToken = null): Response
    {
        $initData = $request->header('X-Telegram-Init-Data')
            ?: $request->input('init_data')
            ?: $request->input('initData');

        if (! $initData) {
            return response()->json(['message' => '缺少 initData'], 401);
        }

        $parsed = [];
        parse_str($initData, $parsed);

        $hash = $parsed['hash'] ?? '';

        if (! $hash) {
            return response()->json(['message' => 'initData 缺少 hash'], 401);
        }

        unset($parsed['hash']);
        ksort($parsed);

        $dataCheckString = collect($parsed)
            ->map(fn ($value, $key) => $key . '=' . $value)
            ->implode("\n");

        $token = $botToken ?: config('wallet.bot_token') ?: config('telegram.bot_token');

        if (! $token) {
            return response()->json(['message' => '未配置 bot token，无法校验身份'], 500);
        }

        $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);
        $computed = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($computed, $hash)) {
            return response()->json(['message' => 'initData 校验失败'], 401);
        }

        // 防旧数据重放
        $authDate = (int) ($parsed['auth_date'] ?? 0);
        $ttl = (int) config('wallet.miniapp.init_data_ttl', 3600);

        if ($authDate <= 0 || abs(time() - $authDate) > $ttl) {
            return response()->json(['message' => 'initData 已过期'], 401);
        }

        $user = json_decode((string) ($parsed['user'] ?? '{}'), true) ?: [];
        $userId = (int) ($user['id'] ?? 0);

        if ($userId <= 0) {
            return response()->json(['message' => 'initData 缺少用户身份'], 401);
        }

        $request->attributes->set('telegram_user_id', $userId);
        $request->attributes->set('telegram_user', $user);

        return $next($request);
    }
}
