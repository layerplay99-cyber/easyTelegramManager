<?php

declare(strict_types=1);

namespace Modules\Telegram\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Telegram\Contracts\WalletGateway;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Models\Member;
use Modules\Telegram\Models\RechargeOrder;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Models\Wallet;
use Modules\Telegram\Models\WithdrawOrder;
use Modules\Telegram\Services\Wallet\UpstreamWalletGateway;

/**
 * 钱包编排服务（平台侧）
 *
 * 这一层是「钱」的唯一入口：指令、MiniApp、后台调账都走这里，
 * 外部请求永远不能传余额字段，只能提交「我要充值/提现多少」，
 * 真正的加钱/扣钱只发生在 —— 上游回调验签通过后，或后台人工调账。
 *
 * 资金安全约定：
 *   1. 入账只认上游回调 + 验签（verifyCallback），不接受任何"直接入账"参数；
 *   2. 提现先冻结再发上游，失败即解冻，绝不凭请求直接扣余额；
 *   3. 幂等键 idempotency_key 唯一，重复提交不会重复建单；
 *   4. 所有外部下单都带 nonce + timestamp 防重放；
 *   5. 金额受 config/wallet.php 的 default_limits 约束。
 */
class WalletService
{
    public function __construct(
        protected RechargeService $rechargeService,
        protected WithdrawService $withdrawService,
        protected RiskControlService $riskControlService,
        protected SecurityService $securityService,
        protected NotificationService $notificationService,
    ) {
    }

    /**
     * 网关实现可在 config/wallet.php 的 gateway 里替换（接新上游时不改上层代码）
     */
    public function gateway(): WalletGateway
    {
        $class = config('wallet.gateway') ?: UpstreamWalletGateway::class;

        return app($class);
    }

    /**
     * 按 telegram 用户取会员，没有就开户（机器人指令/MiniApp 入口）
     */
    public function resolveMember(int $telegramUserId, array $attributes = []): Member
    {
        $member = Member::query()->where('telegram_user_id', $telegramUserId)->first();

        if ($member) {
            return $member;
        }

        return Member::query()->create(array_merge([
            'telegram_user_id' => $telegramUserId,
            'status' => Member::STATUS_NORMAL,
            'register_ip' => request()->ip(),
        ], $attributes));
    }

    /**
     * 取（或建）钱包
     */
    public function wallet(int $memberId, string $currency): Wallet
    {
        // 余额字段不在 fillable 里（防批量赋值改钱），这里只给状态，金额走表默认值
        return Wallet::query()->firstOrCreate(
            ['member_id' => $memberId, 'currency' => $currency],
            ['status' => Wallet::STATUS_NORMAL]
        );
    }

    /**
     * 查余额（平台侧为准）
     */
    public function balance(Member $member, string $currency = 'CNY'): array
    {
        $wallet = $this->wallet($member->id, $currency);

        return [
            'currency' => $currency,
            'balance' => (float) $wallet->balance,
            'frozen' => (float) $wallet->frozen_balance,
            'total_recharge' => (float) $wallet->total_recharge,
            'total_withdraw' => (float) $wallet->total_withdraw,
        ];
    }

    /**
     * 充值下单
     *
     * @param array $extra 可选：channel_id（覆盖上游默认）、notify_url、idempotency_key、nonce、timestamp
     * @return array{order_no: string, pay_url: ?string, amount: float, expired_at: mixed}
     */
    public function createRecharge(
        Member $member,
        string $currency,
        float $amount,
        ThirdApiConfig $upstream,
        array $extra = []
    ): array {
        $this->guardReplay($extra);
        $this->checkAmount('recharge', $currency, $amount);

        $idempotencyKey = $extra['idempotency_key'] ?? null;

        if ($idempotencyKey) {
            $exists = RechargeOrder::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($exists) {
                $info = json_decode((string) $exists->pay_info, true) ?: [];

                return [
                    'order_no' => $exists->order_no,
                    'pay_url' => $info['pay_url'] ?? null,
                    'amount' => (float) $exists->amount,
                    'expired_at' => $exists->expired_at,
                ];
            }
        }

        $riskCheck = $this->riskControlService->checkRecharge($member, $amount, $currency);

        if (! $riskCheck['passed']) {
            throw new \Exception($riskCheck['message']);
        }

        $order = null;

        DB::transaction(function () use (&$order, $member, $currency, $amount, $upstream, $extra, $idempotencyKey) {
            $order = RechargeOrder::create([
                'order_no' => RechargeOrder::generateOrderNo(),
                'member_id' => $member->id,
                'channel_id' => null,
                'third_config_id' => $upstream->id,
                'currency' => $currency,
                'amount' => $amount,
                'fee' => 0,
                'actual_amount' => $amount,
                'pay_method' => $extra['channel_id'] ?? $upstream->channel_id ?? 'upstream',
                'status' => RechargeOrder::STATUS_PENDING,
                'expired_at' => now()->addMinutes((int) config('wallet.recharge_expire_minutes', 30)),
                'request_ip' => request()->ip(),
                'nonce' => $extra['nonce'] ?? null,
                'timestamp' => $extra['timestamp'] ?? time(),
                'idempotency_key' => $idempotencyKey,
            ]);
        });

        // 向上游拉单：拿支付链接
        $result = $this->gateway()->createRecharge($upstream, [
            'order_no' => $order->order_no,
            'amount' => $amount,
            'currency' => $currency,
            'channel_id' => $extra['channel_id'] ?? $upstream->channel_id,
            'notify_url' => $extra['notify_url'] ?? route('api.telegram.wallet.recharge.callback', absolute: true),
        ]);

        $order->third_order_no = $result['third_order_no'];
        // 支付链接存在 pay_info（该表没有 pay_url 列）
        $order->pay_info = json_encode(['pay_url' => $result['pay_url']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! $result['success']) {
            // 拉单失败：订单留在待支付，等查单/回调兜底，不吞掉失败
            Log::warning('[钱包] 充值拉单失败', ['order_no' => $order->order_no, 'message' => $result['message']]);
        }

        $order->save();

        return [
            'order_no' => $order->order_no,
            'pay_url' => $result['pay_url'],
            'amount' => (float) $order->amount,
            'expired_at' => $order->expired_at,
        ];
    }

    /**
     * 提现下单：先冻结余额，再发上游
     *
     * @return array{order_no: string, amount: float}
     */
    public function createWithdraw(
        Member $member,
        string $currency,
        float $amount,
        ThirdApiConfig $upstream,
        array $extra = []
    ): array {
        $this->guardReplay($extra);
        $this->checkAmount('withdraw', $currency, $amount);

        $idempotencyKey = $extra['idempotency_key'] ?? null;

        if ($idempotencyKey) {
            $exists = WithdrawOrder::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($exists) {
                return ['order_no' => $exists->order_no, 'amount' => (float) $exists->amount];
            }
        }

        $riskCheck = $this->riskControlService->checkWithdraw($member, $amount, $currency);

        if (! $riskCheck['passed']) {
            throw new \Exception($riskCheck['message']);
        }

        $wallet = $this->wallet($member->id, $currency);

        $order = DB::transaction(function () use ($wallet, $member, $currency, $amount, $upstream, $extra, $idempotencyKey) {
            // 先锁钱包再冻结，余额不足直接抛
            $locked = Wallet::query()->where('id', $wallet->id)->lockForUpdate()->first();
            $locked->freeze($amount);

            return WithdrawOrder::create([
                'order_no' => WithdrawOrder::generateOrderNo(),
                'member_id' => $member->id,
                'channel_id' => null,
                'third_config_id' => $upstream->id,
                'currency' => $currency,
                'amount' => $amount,
                'fee' => 0,
                'actual_amount' => $amount,
                'withdraw_method' => $extra['withdraw_method'] ?? 'upstream',
                'withdraw_info' => json_encode($extra['withdraw_info'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => WithdrawOrder::STATUS_PENDING,
                'request_ip' => request()->ip(),
                'nonce' => $extra['nonce'] ?? null,
                'timestamp' => $extra['timestamp'] ?? time(),
                'idempotency_key' => $idempotencyKey,
            ]);
        });

        $result = $this->gateway()->createWithdraw($upstream, [
            'order_no' => $order->order_no,
            'amount' => $amount,
            'currency' => $currency,
            'channel_id' => $extra['channel_id'] ?? $upstream->channel_id,
            'account' => $extra['account'] ?? null,
            'notify_url' => $extra['notify_url'] ?? route('api.telegram.wallet.withdraw.callback', absolute: true),
        ]);

        if (! $result['success']) {
            // 上游没接住：立刻解冻，不能让钱卡在冻结里。
            // 注意传模型而不是订单号：cancelOrder 按主键/模型取单，传单号会查不到，
            // 那样解冻不会执行，用户的钱就冻在那儿了。
            $this->withdrawService->cancelOrder($order, '上游受理失败：' . ($result['message'] ?: '未知原因'));

            throw new \Exception('提现受理失败：' . ($result['message'] ?: '请稍后重试'));
        }

        $order->third_order_no = $result['third_order_no'];
        $order->status = WithdrawOrder::STATUS_PROCESSING;
        $order->processed_at = now();
        $order->save();

        return ['order_no' => $order->order_no, 'amount' => (float) $order->amount];
    }

    /**
     * 充值回调：验签通过才入账
     */
    public function handleRechargeCallback(ThirdApiConfig $upstream, array $payload): bool
    {
        if (! $this->gateway()->verifyCallback($upstream, $payload)) {
            Log::warning('[钱包] 充值回调验签失败', ['payload' => $payload]);

            return false;
        }

        // 网关已按上游自己的签名规则验过，这里不再二次校验
        return $this->rechargeService->handleCallback($payload, verified: true);
    }

    /**
     * 提现回调：验签通过才推进状态
     */
    public function handleWithdrawCallback(ThirdApiConfig $upstream, array $payload): bool
    {
        if (! $this->gateway()->verifyCallback($upstream, $payload)) {
            Log::warning('[钱包] 提现回调验签失败', ['payload' => $payload]);

            return false;
        }

        $orderNo = $payload['order_no'] ?? null;

        if (! $orderNo) {
            return false;
        }

        // 先按单号取出订单，再以模型推进状态（completeOrder/cancelOrder 认主键或模型）
        $order = WithdrawOrder::query()->where('order_no', $orderNo)->first();

        if (! $order) {
            Log::warning('[钱包] 提现回调订单不存在', ['order_no' => $orderNo]);

            return false;
        }

        $status = $this->gateway()->resolveStatus($payload);

        if ($status === 'failed') {
            return $this->withdrawService->cancelOrder($order, '上游返回失败');
        }

        return $this->withdrawService->completeOrder($order);
    }

    /**
     * 按订单号向上游查单（补偿：回调丢了也能推进）
     */
    public function queryRecharge(RechargeOrder $order): array
    {
        $upstream = $order->thirdConfig;

        if (! $upstream) {
            return ['success' => false, 'message' => '订单未绑定上游'];
        }

        return $this->gateway()->queryRecharge($upstream, [
            'order_no' => $order->order_no,
            'third_order_no' => $order->third_order_no,
        ]);
    }

    /**
     * 该业务由哪个上游承接
     *
     * 后台在功能配置里选（features.config.upstream_id），
     * 没有就用功能绑定上的上游，都没有就直接报错——绝不允许"不知道发给谁"还下单。
     */
    public function upstreamFor(string $featureKey, ?int $fallbackThirdConfigId = null): ThirdApiConfig
    {
        $feature = $this->findFeature($featureKey);

        $id = (int) data_get($feature?->config, 'upstream_id') ?: (int) $fallbackThirdConfigId;

        $upstream = $id > 0 ? ThirdApiConfig::query()->find($id) : null;

        if (! $upstream) {
            throw new \Exception('未配置承接该业务的上游，请在后台「功能列表 → 该功能的配置」里选择上游');
        }

        return $upstream;
    }

    /**
     * 按功能标识找功能
     *
     * 后台里的功能标识是 custom:walletRecharge（带前缀、驼峰），
     * 外部调用方（MiniApp H5）更习惯写 wallet.recharge，
     * 这里两种写法都认：去掉前缀与非字母数字后比较。
     */
    protected function findFeature(string $featureKey): ?Features
    {
        $feature = Features::query()->where('feature', $featureKey)->first();

        if ($feature) {
            return $feature;
        }

        $normalize = static fn (string $value): string => strtolower(
            preg_replace('/[^a-zA-Z0-9]/', '', (string) \Illuminate\Support\Str::afterLast($value, ':'))
        );

        $target = $normalize($featureKey);

        return Features::query()->get()->first(fn (Features $item) => $normalize($item->feature) === $target);
    }

    /**
     * 查单条订单（带会员归属校验，防止越权查别人的单）
     */
    public function findOrder(string $orderNo, int $memberId): ?array
    {
        $recharge = RechargeOrder::query()->where('order_no', $orderNo)->where('member_id', $memberId)->first();

        if ($recharge) {
            return [
                'type' => 'recharge',
                'order_no' => $recharge->order_no,
                'amount' => (float) $recharge->amount,
                'currency' => $recharge->currency,
                'status' => $recharge->status,
                'status_text' => $recharge->getStatusText(),
                'pay_url' => json_decode((string) $recharge->pay_info, true)['pay_url'] ?? null,
                'created_at' => $recharge->created_at,
            ];
        }

        $withdraw = WithdrawOrder::query()->where('order_no', $orderNo)->where('member_id', $memberId)->first();

        if (! $withdraw) {
            return null;
        }

        return [
            'type' => 'withdraw',
            'order_no' => $withdraw->order_no,
            'amount' => (float) $withdraw->amount,
            'currency' => $withdraw->currency,
            'status' => $withdraw->status,
            'status_text' => $withdraw->getStatusText(),
            'pay_url' => null,
            'created_at' => $withdraw->created_at,
        ];
    }

    /**
     * 最近订单
     */
    public function recentOrders(int $memberId, int $limit = 20): array
    {
        $recharges = RechargeOrder::query()->where('member_id', $memberId)->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($o) => [
                'type' => 'recharge',
                'order_no' => $o->order_no,
                'amount' => (float) $o->amount,
                'currency' => $o->currency,
                'status' => $o->status,
                'status_text' => $o->getStatusText(),
                'created_at' => $o->created_at,
            ])->all();

        $withdraws = WithdrawOrder::query()->where('member_id', $memberId)->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($o) => [
                'type' => 'withdraw',
                'order_no' => $o->order_no,
                'amount' => (float) $o->amount,
                'currency' => $o->currency,
                'status' => $o->status,
                'status_text' => $o->getStatusText(),
                'created_at' => $o->created_at,
            ])->all();

        return array_slice(array_merge($recharges, $withdraws), 0, $limit);
    }

    /**
     * 防重放：外部入口必须带 nonce + timestamp，缺一不可
     */
    protected function guardReplay(array $extra): void
    {
        if (! empty($extra['skip_replay_check'])) {
            return;
        }

        $nonce = $extra['nonce'] ?? null;
        $timestamp = $extra['timestamp'] ?? null;

        if (! $nonce || ! $timestamp) {
            throw new \Exception('缺少防重放参数（nonce / timestamp）');
        }

        $this->securityService->checkReplay((string) $nonce, (int) $timestamp);
    }

    /**
     * 金额上下限（config/wallet.php 的 default_limits）
     */
    protected function checkAmount(string $type, string $currency, float $amount): void
    {
        $limit = config("wallet.default_limits.{$type}.{$currency}")
            ?? config("wallet.default_limits.{$type}.USDT")
            ?? [];

        if ($amount <= 0) {
            throw new \Exception('金额必须大于 0');
        }

        if (isset($limit['min']) && $amount < (float) $limit['min']) {
            throw new \Exception(sprintf('单笔最小 %s %s', $limit['min'], $currency));
        }

        if (isset($limit['max']) && $amount > (float) $limit['max']) {
            throw new \Exception(sprintf('单笔最大 %s %s', $limit['max'], $currency));
        }
    }
}
