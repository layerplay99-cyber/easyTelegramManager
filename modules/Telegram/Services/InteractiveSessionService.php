<?php

declare(strict_types=1);

namespace Modules\Telegram\Services;

use Illuminate\Support\Str;
use Modules\Telegram\Models\InteractiveSession;

/**
 * 交互会话：一次「上游回调 → 群里按钮 → 点击 → 调上游」的完整生命周期
 *
 * 安全要点（旧实现缺的就是这些）：
 *   1) code 是随机串，不可枚举——按钮里只带它，业务数据全留服务端
 *   2) claim() 用「where status=pending 的原子更新」抢锁，
 *      两个人同时点、或 Telegram 重投，只会有一个拿到执行权
 *   3) 每次操作都校验 chat_id / bot_id 归属，防止按钮被拿到别的群点
 */
class InteractiveSessionService
{
    /**
     * 生成按钮句柄（12 位字母数字，不可枚举）
     */
    public function newCode(): string
    {
        do {
            $code = Str::upper(Str::random(12));
            $code = preg_replace('/[^A-Z0-9]/', '', $code) ?? '';
        } while (strlen($code) < 10 || $this->findByCode($code) !== null);

        return substr($code, 0, 12);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): InteractiveSession
    {
        $now = time();

        $data = array_merge([
            'business_type' => 'trade',
            'status' => InteractiveSession::STATUS_PENDING,
            'step' => 0,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => 0,
        ], $data);

        $data['code'] ??= $this->newCode();
        $data['expires_at'] ??= $now + 86400;

        return InteractiveSession::query()->create($data);
    }

    public function findByCode(string $code): ?InteractiveSession
    {
        if ($code === '') {
            return null;
        }

        return InteractiveSession::query()->where('code', $code)->first();
    }

    /**
     * 幂等：同一张上游单据不重复建会话
     */
    public function findByDedupKey(string $dedupKey): ?InteractiveSession
    {
        if ($dedupKey === '') {
            return null;
        }

        return InteractiveSession::query()
            ->where('dedup_key', $dedupKey)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 抢执行权：只有 pending 能被抢到，抢到即变 processing
     *
     * 失败即「已被处理 / 正在处理」，调用方直接提示，不要重复提交上游。
     */
    public function claim(InteractiveSession $session): bool
    {
        $affected = InteractiveSession::query()
            ->where('id', $session->id)
            ->where('status', InteractiveSession::STATUS_PENDING)
            ->update([
                'status' => InteractiveSession::STATUS_PROCESSING,
                'updated_at' => time(),
            ]);

        if ($affected === 0) {
            return false;
        }

        $session->status = InteractiveSession::STATUS_PROCESSING;

        return true;
    }

    /**
     * 放行：上游失败时回滚成 pending，允许重试（并累加次数）
     */
    public function release(InteractiveSession $session): void
    {
        InteractiveSession::query()
            ->where('id', $session->id)
            ->update([
                'status' => InteractiveSession::STATUS_PENDING,
                'attempts' => (int) $session->attempts + 1,
                'pending_act' => null,
                'updated_at' => time(),
            ]);

        $session->status = InteractiveSession::STATUS_PENDING;
        $session->attempts = (int) $session->attempts + 1;
        $session->pending_act = null;
    }

    /**
     * 结束会话
     *
     * @param array<string, mixed> $result
     */
    public function finish(
        InteractiveSession $session,
        string $status,
        array $result = [],
        ?int $operatorId = null,
        ?string $operatorName = null
    ): void {
        InteractiveSession::query()
            ->where('id', $session->id)
            ->update([
                'status' => $status,
                'result' => $result ?: null,
                'operator_user_id' => $operatorId ?: $session->operator_user_id,
                'operator_name' => $operatorName ?: $session->operator_name,
                'pending_act' => null,
                'updated_at' => time(),
            ]);

        $session->status = $status;
        $session->result = $result ?: null;
        $session->pending_act = null;

        if ($operatorId) {
            $session->operator_user_id = $operatorId;
            $session->operator_name = $operatorName;
        }
    }

    /**
     * 进入二次确认：暂存动作，等待下一步
     */
    public function hold(InteractiveSession $session, int $step, string $act): void
    {
        InteractiveSession::query()
            ->where('id', $session->id)
            ->update([
                'step' => $step,
                'pending_act' => $act,
                'status' => InteractiveSession::STATUS_PENDING,
                'updated_at' => time(),
            ]);

        $session->step = $step;
        $session->pending_act = $act;
        $session->status = InteractiveSession::STATUS_PENDING;
    }

    /**
     * 标记过期（定时任务/查询时顺手清理，不依赖 cron 也能自愈）
     */
    public function expire(int $limit = 500): int
    {
        return InteractiveSession::query()
            ->where('status', InteractiveSession::STATUS_PENDING)
            ->where('expires_at', '>', 0)
            ->where('expires_at', '<', time())
            ->limit($limit)
            ->update([
                'status' => InteractiveSession::STATUS_EXPIRED,
                'updated_at' => time(),
            ]);
    }
}
