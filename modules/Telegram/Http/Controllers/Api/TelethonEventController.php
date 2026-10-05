<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Telegram\Events\UserGroupMembershipEvent;
use Modules\Telegram\Jobs\CollectEmoji;
use Modules\Telegram\Models\TelegramApiUsers;

/**
 * Telethon（Python）事件回调入口。
 *
 * Python 服务把「群消息自定义 emoji」「登录账号进群/退群」POST 回来，
 * 转成既有队列任务/事件，复用原落库与监听器链路（等价原 SessionEventHandler）。
 *
 * 鉴权：请求头 X-Token == config('telethon.callback_token')。
 */
class TelethonEventController
{
    private function ok(Request $r): bool
    {
        $t = (string) config('telethon.callback_token', '');
        return $t !== '' && hash_equals($t, (string) $r->header('X-Token', ''));
    }

    public function emoji(Request $request): JsonResponse
    {
        if (! $this->ok($request)) {
            return response()->json(['ok' => false], 401);
        }
        $session = (string) $request->input('session', '');
        $entities = (array) $request->input('entities', []);
        $text = (string) $request->input('text', '');
        if ($session !== '' && ! empty($entities)) {
            CollectEmoji::dispatch($session, $entities, $text);
        }
        return response()->json(['ok' => true]);
    }

    public function member(Request $request): JsonResponse
    {
        if (! $this->ok($request)) {
            return response()->json(['ok' => false], 401);
        }
        $session = (string) $request->input('session', '');
        $chatId = (string) $request->input('chat_id', '');
        $userId = (int) $request->input('user_id', 0);
        $action = (string) $request->input('action', '');
        $account = TelegramApiUsers::where('session_file', $session)->first();
        if ($account && $chatId !== '' && $userId > 0 && in_array($action, ['joined', 'left'], true)) {
            UserGroupMembershipEvent::dispatch(
                (string) $account->app_id,
                $chatId,
                $action,
                $userId,
                $account->creator_id
            );
        }
        return response()->json(['ok' => true]);
    }
}