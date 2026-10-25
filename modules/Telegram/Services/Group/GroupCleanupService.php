<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Group;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\GroupConfigs;
use Modules\Telegram\Services\Feature\FeatureDataStore;
use Throwable;

/**
 * 群清理：删掉群本体，并连带清掉这个群的所有配置与绑定。
 *
 * 两个入口共用，保证「机器人被踢」和「后台人工删除」的下游数据一致：
 *   1) ListenBotInGroupService::handleBotKicked()  机器人被移出群
 *   2) BotGroupsController::destroy()              后台人工删除
 *
 * 为什么必须级联：只删 bot_groups 的话
 *   - group_configs / service_peoples 变孤儿（service_peoples 每次同步都新建，越堆越多）
 *   - merchant_routes、feature_data 里的群级绑定留着，群下次进来时直接"复活"成一堆旧配置
 *   - 缓存不清，删完仍返回旧配置
 *
 * 关于「删了又复活」：群被删后机器人往往还在群里，下一条 update 会触发
 * ensureChatSynced() 把它重建回来。所以这里额外写一个删除标记（墓碑），
 * ensureChatSynced() 见到墓碑就跳过；机器人重新入群（my_chat_member 为
 * member/administrator）时再清掉墓碑，允许重新入库。
 */
class GroupCleanupService
{
    /**
     * 墓碑保留天数。
     *
     * 用缓存而不是建表：墓碑只是给兜底同步看的提示，缓存丢了最坏结果是群被重建
     * （再删一次即可），不会造成数据错误。给个 TTL 是为了兜住「清墓碑没执行到」
     * 的极端情况，不至于让群永远进不来。
     */
    public const TOMBSTONE_TTL_DAYS = 30;

    /**
     * 硬删一个群及其全部关联数据
     *
     * @param int|string      $chatId Telegram 群 ID
     * @param int|string|null $botId  机器人 ID；传 null 时按 chat_id 全删（不区分机器人）
     * @return array<string, int> 各类数据的删除条数，便于打日志/排查
     */
    public function deleteGroup(int|string $chatId, int|string|null $botId = null): array
    {
        $counts = [
            'groups'          => 0,
            'configs'         => 0,
            'service_peoples' => 0,
            'admins'          => 0,
            'members'         => 0,
            'binds'           => 0,
            'merchant_routes' => 0,
            'sessions'        => 0,
            'feature_data'    => 0,
        ];

        $featureIds = [];

        try {
            DB::transaction(function () use (&$counts, &$featureIds, $chatId, $botId): void {
                // 群本体：指定了 bot 就只删「这个机器人的行 + 没有 bot 的 app 同步行」，
                // 群里还有别的机器人时不误伤它们。
                $groupIds = DB::table('bot_groups')
                    ->where('chat_id', $chatId)
                    ->when($botId !== null, function ($q) use ($botId): void {
                        $q->where(function ($q2) use ($botId): void {
                            $q2->where('bot_id', $botId)
                                ->orWhereNull('bot_id')
                                ->orWhere('bot_id', 0);
                        });
                    })
                    ->pluck('id')
                    ->all();

                if (empty($groupIds)) {
                    return;
                }

                $counts['groups'] = DB::table('bot_groups')->whereIn('id', $groupIds)->delete();

                // 以 bot_groups.id 为外键的
                $counts['configs']         = DB::table('group_configs')->whereIn('groupId', $groupIds)->delete();
                $counts['service_peoples'] = DB::table('service_peoples')->whereIn('group_id', $groupIds)->delete();

                // 以 chat_id 为外键的（群没了这些记录也没意义）
                $counts['admins']  = DB::table('group_admins')->where('chat_id', $chatId)->delete();
                $counts['members'] = DB::table('group_members')->where('chat_id', $chatId)->delete();

                // 群级功能数据（/bm、/trsq 之类的绑定值）：按功能整体缓存，
                // 删之前先记住涉及哪些功能，好在外面逐个失效缓存。
                $featureIds = DB::table('feature_data')
                    ->where('scope_type', 'group')
                    ->where('scope_id', $chatId)
                    ->pluck('feature_id')
                    ->all();

                $counts['feature_data'] = DB::table('feature_data')
                    ->where('scope_type', 'group')
                    ->where('scope_id', $chatId)
                    ->delete();

                // bot 维度的数据：只动本机器人的 /bd 绑定、按钮会话、功能绑定
                $botScoped = function (string $table) use ($chatId, $botId) {
                    $q = DB::table($table)->where('chat_id', $chatId);

                    if ($botId !== null) {
                        $q->where('bot_id', $botId);
                    }

                    return $q->delete();
                };

                $counts['binds']           = $botScoped('features_binds');
                $counts['merchant_routes'] = $botScoped('merchant_routes');
                $counts['sessions']        = $botScoped('interactive_sessions');
            }, 3);
        } catch (Throwable $e) {
            Log::error('删除群及关联数据失败', [
                'chat_id' => $chatId,
                'bot_id'  => $botId,
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        }

        // 缓存清理放在事务外：清缓存失败不该把删除回滚掉
        $this->flushCache($chatId, $botId, $featureIds);

        // 墓碑：别让兜底同步把它重建回来
        $this->markDeleted($chatId, $botId);

        return $counts;
    }

    /**
     * 清掉这个群的所有缓存
     *
     * @param array<int, int|string> $featureIds 受影响的功能 ID（feature_data 按功能缓存）
     */
    protected function flushCache(int|string $chatId, int|string|null $botId, array $featureIds): void
    {
        try {
            Cache::forget(GroupConfigs::configCacheKey($chatId));

            if ($botId !== null) {
                Cache::forget("telegram:chat:synced:{$botId}:{$chatId}");
                Cache::forget("feature:binds:{$botId}:{$chatId}");
                Cache::forget("feature:binds:{$botId}:0");
            }

            $store = app(FeatureDataStore::class);

            foreach ($featureIds as $featureId) {
                $store->forgetCache((int) $featureId);
            }

            // group_configs 大数组 + 语种缓存：群已不存在，
            // refreshCacheForChat() 会把这个 chatId 从大数组里摘掉并失效语种缓存。
            BotGroups::refreshCacheForChat($chatId);
        } catch (Throwable $e) {
            Log::warning('删除群后清理缓存失败', [
                'chat_id' => $chatId,
                'bot_id'  => $botId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    public static function tombstoneKey(int|string|null $botId, int|string $chatId): string
    {
        return 'telegram:group:deleted:' . (string) $botId . ':' . (string) $chatId;
    }

    /**
     * 打删除标记：兜底同步看到就跳过，避免刚删的群被重建
     */
    public function markDeleted(int|string $chatId, int|string|null $botId = null): void
    {
        Cache::put(
            self::tombstoneKey($botId, $chatId),
            time(),
            now()->addDays(self::TOMBSTONE_TTL_DAYS)
        );
    }

    public function isDeleted(int|string $chatId, int|string|null $botId = null): bool
    {
        return Cache::has(self::tombstoneKey($botId, $chatId));
    }

    /**
     * 清删除标记：机器人重新入群时调用，允许它再次入库
     */
    public function clearDeletedMark(int|string $chatId, int|string|null $botId = null): void
    {
        Cache::forget(self::tombstoneKey($botId, $chatId));
    }
}
