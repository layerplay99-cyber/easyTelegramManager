<?php

declare(strict_types=1);

namespace Modules\Telegram\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Telegram\Models\RechargeOrder;
use Modules\Telegram\Models\WithdrawOrder;
use Modules\Telegram\Services\NotificationService;

/**
 * 订单通知（充值到账 / 提现完成）
 *
 * 为什么单独做成 job：
 *   通知要发 Telegram 消息（外部 HTTP，正常几百毫秒，429 限流重试最坏几十秒）。
 *   放在回调请求里会占住 FPM 进程；放在事务里更糟——事务持有订单行锁 + 钱包行锁，
 *   在里面发网络请求会把同一钱包的并发回调全部串行化，
 *   最终上游等不到响应 → 超时 → 状态不对 / 挂单。
 *
 * 边界（重要）：
 *   账务本身仍然完全同步 —— 钱在事务里入账、订单置为完成后才返回 success。
 *   这里异步的只是「告知」这一步，发生在事务提交之后，
 *   通知失败不影响账目准确性，只影响客户是否收到消息，因此有重试。
 *
 * 由 DB::afterCommit 触发：保证最外层事务真正提交后才入队，回滚则不会误发。
 *
 * @see \Modules\Telegram\Services\RechargeService::completeOrder()
 * @see \Modules\Telegram\Services\WithdrawService::completeOrder()
 */
class SendOrderNotificationJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /**
     * 最大尝试次数：通知影响客户感知，失败要重试
     */
    public int $tries = 3;

    /**
     * 单次超时（秒）
     */
    public int $timeout = 30;

    /**
     * 重试间隔（秒）
     */
    public function backoff(): array
    {
        return [5, 20];
    }

    public function __construct(
        public string $type,
        public int $orderId,
    ) {
    }

    public function handle(): void
    {
        // 服务在 handle() 内解析，不在构造函数注入：
        // 入队序列化时强类型服务属性可能带未初始化字段，会直接抛异常导致任务进不了队列。
        $notification = app(NotificationService::class);

        if ($this->type === 'recharge') {
            $order = RechargeOrder::query()->find($this->orderId);

            if ($order) {
                $notification->sendRechargeNotification($order);
            }

            return;
        }

        $order = WithdrawOrder::query()->find($this->orderId);

        if ($order) {
            $notification->sendWithdrawCompleteNotification($order);
        }
    }
}
