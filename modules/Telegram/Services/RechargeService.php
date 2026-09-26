<?php

namespace Modules\Telegram\Services;

use Modules\Telegram\Models\Member;
use Modules\Telegram\Models\Wallet;
use Modules\Telegram\Models\RechargeOrder;
use Modules\Telegram\Models\PaymentChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 充值服务类
 */
class RechargeService
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
     * 创建充值订单
     * @param array $data 订单数据
     * @return RechargeOrder
     * @throws \Exception|\Throwable
     */
    public function createOrder(array $data): RechargeOrder
    {
        // 1. 验证会员
        $member = Member::find($data['member_id']);
        if (!$member || $member->status !== Member::STATUS_NORMAL) {
            throw new \Exception('会员状态异常');
        }

        // 2. 验证支付通道
        $channel = PaymentChannel::find($data['channel_id']);
        if (!$channel || !$channel->isEnabled()) {
            throw new \Exception('支付通道不可用');
        }

        // 3. 验证币种
        if ($channel->currency !== $data['currency']) {
            throw new \Exception('币种不匹配');
        }

        // 4. 验证金额
        if (!$channel->checkAmountLimit($data['amount'])) {
            throw new \Exception("充值金额必须在 {$channel->min_amount} - {$channel->max_amount} 之间");
        }

        // 5. 安全检查
        $ip = request()->ip();

        // IP频率限制
        $this->securityService->checkIpRateLimit($ip, 'recharge', 10, 1);

        // 用户频率限制
        $this->securityService->checkUserRateLimit($member->id, 'recharge', 5, 1);

        // 防重放攻击
        if (isset($data['nonce']) && isset($data['timestamp'])) {
            $this->securityService->checkReplay($data['nonce'], $data['timestamp']);
        }

        // 6. 风控检查
        $riskCheck = $this->riskControlService->checkRecharge($member, $data['amount'], $data['currency']);
        if (!$riskCheck['passed']) {
            throw new \Exception($riskCheck['message']);
        }

        // 7. 计算手续费
        $fee = $channel->calculateFee($data['amount']);
        $actualAmount = $data['amount'] - $fee;

        // 8. 创建订单
        return DB::transaction(function () use ($data, $member, $channel, $fee, $actualAmount, $ip) {
            $order = RechargeOrder::create([
                'order_no' => RechargeOrder::generateOrderNo(),
                'member_id' => $member->id,
                'channel_id' => $channel->id,
                'currency' => $data['currency'],
                'amount' => $data['amount'],
                'fee' => $fee,
                'actual_amount' => $actualAmount,
                'pay_method' => $channel->method,
                'callback_url' => $data['callback_url'] ?? null,
                'status' => RechargeOrder::STATUS_PENDING,
                'expired_at' => now()->addMinutes(30), // 30分钟有效期
                'request_ip' => $ip,
                'nonce' => $data['nonce'] ?? null,
                'timestamp' => $data['timestamp'] ?? time(),
            ]);

            // 更新会员最后活跃时间
            $member->updateLastActive($ip);

            // 记录操作日志
            $this->logOperation($member->id, 'create', $order);

            return $order;
        });
    }

    /**
     * 处理充值回调
     * @param array $callbackData 回调数据
     * @return bool
     * @throws \Exception|\Throwable
     */
    public function handleCallback(array $callbackData): bool
    {
        $orderNo = $callbackData['order_no'] ?? null;
        if (!$orderNo) {
            throw new \Exception('订单号不能为空');
        }

        // 整个回调处理必须在事务内，并用行锁锁住订单，
        // 否则两个并发回调会同时读到 PENDING 各自完成一次 → 重复入账。
        return DB::transaction(function () use ($orderNo, $callbackData) {
            $order = RechargeOrder::where('order_no', $orderNo)->lockForUpdate()->first();
            if (!$order) {
                throw new \Exception('订单不存在');
            }

            // 验证签名
            // 注意：原来是 $channel->config['secret_key'] ?? ''，
            // 但 PaymentChannel 根本没有 config 属性（只有 secret_key 字段和 getFullConfig()），
            // 这里的 $secret 恒为空字符串，等于任何人都能伪造充值回调给自己加钱。
            $channel = $order->channel;
            $secret = (string) ($channel?->secret_key ?? '');

            if ($secret === '') {
                Log::error('支付通道未配置密钥，拒绝回调', [
                    'order_no' => $orderNo,
                    'channel_id' => $order->channel_id,
                ]);

                throw new \Exception('支付通道未配置密钥');
            }

            $sign = $callbackData['sign'] ?? '';

            if (!$this->securityService->verifySign($callbackData, $sign, $secret)) {
                throw new \Exception('签名验证失败');
            }

            // 防止重复回调（在行锁内判断，避免并发绕过）
            if ($order->status !== RechargeOrder::STATUS_PENDING) {
                Log::warning("充值订单已处理，忽略重复回调", ['order_no' => $orderNo]);
                return true;
            }

            // 更新订单状态
            $order->status = RechargeOrder::STATUS_PAID;
            $order->third_order_no = $callbackData['third_order_no'] ?? null;
            $order->pay_info = json_encode($callbackData);
            $order->paid_at = now();
            $order->save();

            // 处理订单完成逻辑
            return $this->completeOrder($order);
        });
    }

    /**
     * 完成充值订单
     * @param RechargeOrder $order
     * @param array $options
     * @return bool
     * @throws \Throwable
     */
    public function completeOrder(RechargeOrder $order, array $options = []): bool
    {
        return DB::transaction(function () use ($order, $options) {
            // 1. 行锁 + 状态校验，保证幂等。
            //    原来直接拿传入的 $order 加钱、不校验状态，
            //    后台补单连点两次 / 并发回调都会重复加余额。
            $locked = RechargeOrder::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \Exception('订单不存在');
            }

            if ($locked->status === RechargeOrder::STATUS_COMPLETED) {
                Log::warning('充值订单已完成，忽略重复入账', ['order_no' => $locked->order_no]);
                return true;
            }

            if (! in_array($locked->status, [RechargeOrder::STATUS_PENDING, RechargeOrder::STATUS_PAID], true)) {
                throw new \Exception('订单状态不允许完成');
            }

            // 2. 获取或创建钱包（拆成先查后建，避免并发下 firstOrCreate 撞唯一索引）
            $wallet = Wallet::where('member_id', $locked->member_id)
                ->where('currency', $locked->currency)
                ->first();

            if (! $wallet) {
                $wallet = Wallet::create([
                    'member_id' => $locked->member_id,
                    'currency' => $locked->currency,
                    'balance' => 0,
                    'frozen_balance' => 0,
                    'status' => Wallet::STATUS_NORMAL,
                ]);
            }

            // 3. 增加余额
            $wallet->addBalance(
                $locked->actual_amount,
                'recharge',
                $locked->order_no,
                [
                    'title' => '充值到账',
                    'description' => "充值订单：{$locked->order_no}",
                    'operator_id' => $options['admin_id'] ?? 0,
                    'operator_type' => isset($options['admin_id']) ? 'admin' : 'system',
                    'ip' => $locked->request_ip,
                    'remark' => $options['remark'] ?? null,
                ]
            );

            // 4. 更新订单状态
            $locked->status = RechargeOrder::STATUS_COMPLETED;
            $locked->completed_at = now();
            if (isset($options['remark'])) {
                $locked->remark = $options['remark'];
            }
            $locked->save();

            // 5. 发送通知到Telegram群
            //    网络 I/O 不能因为失败就把已经入账的余额回滚掉，这里单独兜住
            try {
                $this->notificationService->sendRechargeNotification($locked);
            } catch (\Throwable $e) {
                Log::warning('充值通知发送失败', [
                    'order_no' => $locked->order_no,
                    'error' => $e->getMessage(),
                ]);
            }

            // 6. 记录操作日志
            $this->logOperation($locked->member_id, 'complete', $locked);

            return true;
        });
    }

    /**
     * 取消充值订单
     * @param string $orderNo 订单号
     * @param string $reason 取消原因
     * @return bool
     * @throws \Exception
     */
    public function cancelOrder(string $orderNo, string $reason = '用户取消'): bool
    {
        $order = RechargeOrder::where('order_no', $orderNo)->first();
        if (!$order) {
            throw new \Exception('订单不存在');
        }

        if ($order->status !== RechargeOrder::STATUS_PENDING) {
            throw new \Exception('订单状态不允许取消');
        }

        $order->status = RechargeOrder::STATUS_CANCELLED;
        $order->remark = $reason;
        $order->save();

        $this->logOperation($order->member_id, 'cancel', $order);

        return true;
    }

    /**
     * 记录操作日志
     */
    private function logOperation(int $memberId, string $action, RechargeOrder $order): void
    {
        \Modules\Telegram\Models\OperationLog::create([
            'member_id' => $memberId,
            'module' => 'recharge',
            'action' => $action,
            'method' => request()->method(),
            'url' => request()->url(),
            'params' => json_encode(request()->all()),
            'related_type' => 'RechargeOrder',
            'related_id' => $order->id,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'status' => 1,
        ]);
    }
}
