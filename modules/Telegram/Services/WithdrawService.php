<?php

namespace Modules\Telegram\Services;

use Modules\Telegram\Models\Member;
use Modules\Telegram\Models\Wallet;
use Modules\Telegram\Models\WithdrawOrder;
use Modules\Telegram\Models\PaymentChannel;
use Modules\Telegram\Models\ExchangeRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 提现服务类
 */
class WithdrawService
{
    protected SecurityService $securityService;
    protected RiskControlService $riskControlService;
    protected NotificationService $notificationService;

    public function __construct(
        SecurityService $securityService,
        RiskControlService $riskControlService,
        NotificationService $notificationService
    ) {
        $this->securityService = $securityService;
        $this->riskControlService = $riskControlService;
        $this->notificationService = $notificationService;
    }

    /**
     * 创建提现订单
     * @param array $data 订单数据
     * @return WithdrawOrder
     * @throws \Exception|\Throwable
     */
    public function createOrder(array $data): WithdrawOrder
    {
        // 1. 验证会员
        $member = Member::find($data['member_id']);
        if (!$member || $member->status !== Member::STATUS_NORMAL) {
            throw new \Exception('会员状态异常');
        }

        // 2. 验证支付密码（可选）
        if (isset($data['payment_password']) && !empty($member->payment_password)) {
            if (!$member->verifyPaymentPassword($data['payment_password'])) {
                throw new \Exception('支付密码错误');
            }
        }

        // 3. 验证支付通道
        $channel = PaymentChannel::find($data['channel_id']);
        if (!$channel || !$channel->isEnabled()) {
            throw new \Exception('支付通道不可用');
        }

        // 4. 验证金额
        if (!$channel->checkAmountLimit($data['amount'])) {
            throw new \Exception("提现金额必须在 {$channel->min_amount} - {$channel->max_amount} 之间");
        }

        // 5. 获取钱包
        $wallet = Wallet::where('member_id', $member->id)
            ->where('currency', $data['currency'])
            ->first();

        if (!$wallet) {
            throw new \Exception('钱包不存在');
        }

        // 6. 计算手续费和汇率
        $fee = $channel->calculateFee($data['amount']);
        $totalAmount = $data['amount'] + $fee;

        // 检查余额
        if ($wallet->balance < $totalAmount) {
            throw new \Exception('余额不足');
        }

        // 获取汇率
        $exchangeRate = 1.0;
        if (isset($data['target_currency']) && $data['target_currency'] !== $data['currency']) {
            $exchangeRate = ExchangeRate::getRate($data['currency'], $data['target_currency'], 'sell');
            if (!$exchangeRate) {
                throw new \Exception('暂无汇率，无法提现');
            }
        }

        // 实际到账 = 申请金额 × 汇率。
        // 原来写成 ($data['amount'] - $fee) * $exchangeRate，
        // 但上面冻结时收的是 amount + fee（手续费已经从余额里扣过了），
        // 这里再扣一次等于向用户重复收手续费，且到账金额与解冻/扣减的 amount+fee 对不上。
        $actualAmount = $data['amount'] * $exchangeRate;

        // 7. 安全检查
        $ip = request()->ip();

        // IP频率限制
        $this->securityService->checkIpRateLimit($ip, 'withdraw', 5, 1);

        // 用户频率限制
        $this->securityService->checkUserRateLimit($member->id, 'withdraw', 3, 5);

        // 防重放攻击
        if (isset($data['nonce']) && isset($data['timestamp'])) {
            $this->securityService->checkReplay($data['nonce'], $data['timestamp']);
        }

        // 8. 风控检查
        $riskCheck = $this->riskControlService->checkWithdraw($member, $data['amount'], $data['currency']);
        if (!$riskCheck['passed']) {
            throw new \Exception($riskCheck['message']);
        }

        // 9. 创建订单并冻结余额
        return DB::transaction(function () use (
            $data, $member, $channel, $wallet, $fee, $actualAmount,
            $exchangeRate, $totalAmount, $ip
        ) {
            // 先生成订单号，这样冻结流水能带上单号，便于对账
            $orderNo = WithdrawOrder::generateOrderNo();

            // 冻结余额
            $wallet->freeze($totalAmount, $orderNo, [
                'title' => '提现冻结',
                'description' => "提现订单：{$orderNo}",
                'ip' => $ip,
            ]);

            // 创建订单
            $order = WithdrawOrder::create([
                'order_no' => $orderNo,
                'member_id' => $member->id,
                'channel_id' => $channel->id,
                'currency' => $data['currency'],
                'amount' => $data['amount'],
                'fee' => $fee,
                'actual_amount' => $actualAmount,
                'exchange_rate' => $exchangeRate,
                'withdraw_method' => $channel->method,
                'withdraw_info' => json_encode($data['withdraw_info'] ?? []),
                'status' => WithdrawOrder::STATUS_PENDING,
                'request_ip' => $ip,
                'nonce' => $data['nonce'] ?? null,
                'timestamp' => $data['timestamp'] ?? time(),
            ]);

            // 更新会员最后活跃时间
            $member->updateLastActive($ip);

            // 自动提交到第三方处理
            $this->processWithdraw($order);

            // 记录操作日志
            $this->logOperation($member->id, 'create', $order);

            return $order;
        });
    }

    /**
     * 处理提现（提交到第三方）
     * @param WithdrawOrder $order
     * @return bool
     */
    protected function processWithdraw(WithdrawOrder $order): bool
    {
        // 更新状态为处理中
        $order->status = WithdrawOrder::STATUS_PROCESSING;
        $order->processed_at = now();
        $order->save();

        // TODO: 调用第三方提现API
        // $channel = $order->channel;
        // $result = $this->callThirdPartyWithdraw($order, $channel);

        return true;
    }

    /**
     * 完成提现
     * @param WithdrawOrder $order
     * @param array $options
     * @return bool
     * @throws \Throwable
     */
    public function completeOrder($order, array $options = []): bool
    {
        if (!($order instanceof WithdrawOrder)) {
            $order = WithdrawOrder::find($order);
        }

        if (!$order) {
            throw new \Exception('订单不存在');
        }

        // 状态校验原来在事务外：两个并发请求会同时通过校验，
        // 然后各自 deductFrozen 一次 → 重复扣减冻结余额。
        // 改为在事务内用行锁重新取一次订单再判断。
        return DB::transaction(function () use ($order, $options) {
            $locked = WithdrawOrder::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \Exception('订单不存在');
            }

            if ($locked->status === WithdrawOrder::STATUS_COMPLETED) {
                \Illuminate\Support\Facades\Log::warning('提现订单已完成，忽略重复处理', [
                    'order_no' => $locked->order_no,
                ]);

                return true;
            }

            if (!in_array($locked->status, [WithdrawOrder::STATUS_PENDING, WithdrawOrder::STATUS_PROCESSING])) {
                throw new \Exception('订单状态异常');
            }

            // 获取钱包
            $wallet = Wallet::where('member_id', $locked->member_id)
                ->where('currency', $locked->currency)
                ->first();

            // 扣除冻结余额
            $totalAmount = $locked->amount + $locked->fee;
            $wallet->deductFrozen(
                $totalAmount,
                'withdraw',
                $locked->order_no,
                [
                    'title' => '提现完成',
                    'description' => "提现订单：{$locked->order_no}",
                    'operator_id' => $options['admin_id'] ?? 0,
                    'operator_type' => isset($options['admin_id']) ? 'admin' : 'system',
                    'ip' => $locked->request_ip,
                    'remark' => $options['remark'] ?? null,
                ]
            );

            // 更新订单状态
            $locked->status = WithdrawOrder::STATUS_COMPLETED;
            $locked->third_order_no = $options['third_order_no'] ?? $locked->third_order_no;
            $locked->completed_at = now();
            if (isset($options['remark'])) {
                $locked->remark = $options['remark'];
            }
            $locked->save();

            // 发送通知（网络 I/O 失败不能回滚已完成的账务）
            try {
                $this->notificationService->sendWithdrawCompleteNotification($locked);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('提现完成通知发送失败', [
                    'order_no' => $locked->order_no,
                    'error' => $e->getMessage(),
                ]);
            }

            // 记录操作日志
            $this->logOperation($locked->member_id, 'complete', $locked, $options['admin_id'] ?? 0);

            return true;
        });
    }

    /**
     * 取消提现订单
     * @param WithdrawOrder|int $order
     * @param string $reason 取消原因
     * @return bool
     * @throws \Throwable
     */
    public function cancelOrder($order, string $reason = ''): bool
    {
        if (!($order instanceof WithdrawOrder)) {
            $order = WithdrawOrder::find($order);
        }

        if (!$order) {
            throw new \Exception('订单不存在');
        }

        // 同样把状态校验移进事务并加行锁：
        // 原来并发取消会各自 unfreeze 一次，凭空放大可用余额。
        return DB::transaction(function () use ($order, $reason) {
            $locked = WithdrawOrder::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \Exception('订单不存在');
            }

            if ($locked->status === WithdrawOrder::STATUS_CANCELLED) {
                \Illuminate\Support\Facades\Log::warning('提现订单已取消，忽略重复解冻', [
                    'order_no' => $locked->order_no,
                ]);

                return true;
            }

            if (!$locked->canCancel()) {
                throw new \Exception('订单状态不允许取消');
            }

            // 获取钱包
            $wallet = Wallet::where('member_id', $locked->member_id)
                ->where('currency', $locked->currency)
                ->first();

            // 解冻余额
            $totalAmount = $locked->amount + $locked->fee;
            $wallet->unfreeze($totalAmount, $locked->order_no, [
                'title' => '提现取消解冻',
                'description' => "提现订单：{$locked->order_no}（{$reason}）",
                'remark' => $reason,
                'ip' => $locked->request_ip,
            ]);

            // 更新订单状态
            $locked->status = WithdrawOrder::STATUS_CANCELLED;
            $locked->remark = $reason;
            $locked->save();

            // 记录操作日志
            $this->logOperation($locked->member_id, 'cancel', $locked);

            return true;
        });
    }

    /**
     * 记录操作日志
     */
    private function logOperation(int $memberId, string $action, WithdrawOrder $order, int $operatorId = 0): void
    {
        \Modules\Telegram\Models\OperationLog::create([
            'member_id' => $memberId,
            'admin_id' => $operatorId > 0 ? $operatorId : null,
            'module' => 'withdraw',
            'action' => $action,
            'method' => request()->method(),
            'url' => request()->url(),
            'params' => json_encode(request()->all()),
            'related_type' => 'WithdrawOrder',
            'related_id' => $order->id,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'status' => 1,
        ]);
    }
}
