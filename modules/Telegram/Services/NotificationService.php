<?php

namespace Modules\Telegram\Services;

use Modules\Telegram\Models\Member;
use Modules\Telegram\Models\RechargeOrder;
use Modules\Telegram\Models\WithdrawOrder;
use Modules\Telegram\Models\Notification;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Telegram\Bot\Api;
use Telegram\Bot\Keyboard\Keyboard;
use Illuminate\Support\Facades\Log;

/**
 * 通知服务类
 *
 * 多 bot 多商户：通知一律发回「该订单所属的机器人」，没有全局兜底 bot。
 * 机器人来自订单上的 bot_id（建单时由功能驱动 / MiniApp 按 feature 记下来），
 * 取不到就记日志跳过——宁可发不出去，也不能用别的机器人的 token 往用户群里发。
 */
class NotificationService
{
    public function __construct(
        protected BotApiFactory $botApiFactory
    ) {
    }

    /**
     * 发送充值通知到Telegram群
     * @param RechargeOrder $order
     * @return bool
     */
    public function sendRechargeNotification(RechargeOrder $order): bool
    {
        $member = $order->member;

        $message = "💰 <b>充值到账通知</b>\n\n";
        $message .= "👤 会员：{$member->username}\n";
        $message .= "💵 金额：{$order->actual_amount} {$order->currency}\n";
        $message .= "📝 订单号：{$order->order_no}\n";
        // ⚠️ created_at 是 Carbon，不能直接丢给 date()（PHP 8 会抛 TypeError）
        $message .= "⏰ 时间：" . ($order->created_at?->format('Y-m-d H:i:s') ?? '') . "\n";

        // 保存通知记录
        $this->saveNotification(
            $member->id,
            'recharge',
            '充值到账通知',
            $message,
            'RechargeOrder',
            $order->id
        );

        // 发送到Telegram
        return $this->sendOrderNotification($order, $member->telegram_user_id, $message);
    }

    /**
     * 发送提现审核通知
     * @param WithdrawOrder $order
     * @return bool
     */
    public function sendWithdrawNotification(WithdrawOrder $order): bool
    {
        $member = $order->member;

        $message = "💸 <b>提现申请已提交</b>\n\n";
        $message .= "👤 会员：{$member->username}\n";
        $message .= "💵 金额：{$order->amount} {$order->currency}\n";
        $message .= "💰 到账金额：{$order->actual_amount}\n";
        $message .= "📝 订单号：{$order->order_no}\n";
        $message .= "⏰ 时间：" . ($order->created_at?->format('Y-m-d H:i:s') ?? '') . "\n";
        $message .= "\n正在处理中，请耐心等待。";

        $this->saveNotification(
            $member->id,
            'withdraw',
            '提现申请已提交',
            $message,
            'WithdrawOrder',
            $order->id
        );

        return $this->sendOrderNotification($order, $member->telegram_user_id, $message);
    }

    /**
     * 发送提现完成通知
     * @param WithdrawOrder $order
     * @return bool
     */
    public function sendWithdrawCompleteNotification(WithdrawOrder $order): bool
    {
        $member = $order->member;

        $message = "🎉 <b>提现已完成</b>\n\n";
        $message .= "👤 会员：{$member->username}\n";
        $message .= "💵 金额：{$order->amount} {$order->currency}\n";
        $message .= "💰 到账金额：{$order->actual_amount}\n";
        $message .= "📝 订单号：{$order->order_no}\n";
        $message .= "⏰ 完成时间：" . ($order->completed_at ? $order->completed_at->format('Y-m-d H:i:s') : '') . "\n";
        $message .= "\n请查收您的账户。";

        $this->saveNotification(
            $member->id,
            'withdraw',
            '提现完成通知',
            $message,
            'WithdrawOrder',
            $order->id
        );

        return $this->sendOrderNotification($order, $member->telegram_user_id, $message);
    }

    /**
     * 按订单所属机器人发送通知
     *
     * 多 bot 多商户下没有全局 bot：机器人来自订单的 bot_id，
     * 取不到（历史订单 / 建单时没传）就记日志跳过——
     * 绝不退回「随便找个 bot 发」，那会把 A 商户的到账通知发到 B 的机器人上。
     */
    protected function sendOrderNotification(RechargeOrder|WithdrawOrder $order, int $chatId, string $message): bool
    {
        $botId = (int) ($order->bot_id ?? 0);

        if ($botId <= 0) {
            Log::warning('订单没有记录机器人，通知跳过', [
                'order_no' => $order->order_no,
                'order_id' => $order->id,
            ]);

            return false;
        }

        $bot = Bots::query()->find($botId);

        if (! $bot || empty($bot->api_token)) {
            Log::warning('订单记录的机器人不存在或没有 token，通知跳过', [
                'order_no' => $order->order_no,
                'bot_id' => $botId,
            ]);

            return false;
        }

        return $this->sendToTelegram($this->botApiFactory->forBot($bot), $chatId, $message);
    }

    /**
     * 发送到Telegram群或私聊
     *
     * @param Api|null $telegram 必须显式传机器人客户端——调用方要知道这条消息由哪个 bot 发出；
     *                           传 null 表示没有可用机器人，记日志后跳过
     */
    public function sendToTelegram(?Api $telegram, int $chatId, string $message, array $keyboard = []): bool
    {
        if (! $telegram) {
            Log::warning('没有可用的机器人，通知跳过', ['chat_id' => $chatId]);

            return false;
        }

        try {
            $params = [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ];

            if (!empty($keyboard)) {
                $params['reply_markup'] = Keyboard::make([
                    'inline_keyboard' => $keyboard
                ]);
            }

            $telegram->sendMessage($params);

            return true;
        } catch (\Exception $e) {
            Log::error('发送Telegram消息失败: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'message' => $message
            ]);
            return false;
        }
    }

    /**
     * 保存通知记录
     */
    protected function saveNotification(
        int $memberId,
        string $type,
        string $title,
        string $content,
        ?string $relatedType = null,
        ?int $relatedId = null
    ): void {
        Notification::create([
            'member_id' => $memberId,
            'type' => $type,
            'title' => $title,
            'content' => $content,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'channel' => 'telegram',
            'send_status' => 0,
        ]);
    }

    /**
     * 发送账单通知
     * @param Member $member
     * @param string $period 周期（today, date）
     * @param string $date 日期
     * @param int|null $botId 用哪个机器人发；不传则跳过并记日志
     * @return bool
     */
    public function sendBillNotification(Member $member, string $period, string $date = '', ?int $botId = null): bool
    {
        $ledgerService = app(LedgerService::class);

        if ($period === 'today') {
            $bills = $ledgerService->getTodayBill($member->id);
            $title = '📊 今日账单';
        } else {
            $bills = $ledgerService->getDateBill($member->id, $date);
            $title = "📊 {$date} 账单";
        }

        $message = "<b>{$title}</b>\n\n";
        $message .= "👤 会员：{$member->username}\n\n";

        if (empty($bills['items'])) {
            $message .= "暂无交易记录";
        } else {
            $message .= "💰 总收入：{$bills['total_income']}\n";
            $message .= "💸 总支出：{$bills['total_expense']}\n";
            $message .= "📈 净收益：{$bills['net_amount']}\n\n";
            $message .= "<b>明细：</b>\n";

            foreach ($bills['items'] as $index => $item) {
                $symbol = $item['amount'] > 0 ? '+' : '';
                $message .= ($index + 1) . ". {$item['title']} {$symbol}{$item['amount']} {$item['currency']}\n";
                $message .= "   " . date('H:i:s', $item['created_at']) . "\n";
            }
        }

        return $this->sendToTelegram($this->apiFor($botId), $member->telegram_user_id, $message);
    }

    /**
     * 发送余额通知
     * @param Member $member
     * @param int|null $botId 用哪个机器人发；不传则跳过并记日志
     * @return bool
     */
    public function sendBalanceNotification(Member $member, ?int $botId = null): bool
    {
        $wallets = $member->wallets()->where('status', 1)->get();

        $message = "💰 <b>账户余额</b>\n\n";
        $message .= "👤 会员：{$member->username}\n\n";

        if ($wallets->isEmpty()) {
            $message .= "暂无钱包";
        } else {
            foreach ($wallets as $wallet) {
                $message .= "💵 {$wallet->currency}\n";
                $message .= "   可用：{$wallet->balance}\n";
                $message .= "   冻结：{$wallet->frozen_balance}\n";
                $message .= "   累计充值：{$wallet->total_recharge}\n";
                $message .= "   累计提现：{$wallet->total_withdraw}\n\n";
            }
        }

        return $this->sendToTelegram($this->apiFor($botId), $member->telegram_user_id, $message);
    }

    /**
     * 按 bot_id 取客户端；取不到返回 null（发送方据此跳过并记日志）
     */
    protected function apiFor(?int $botId): ?Api
    {
        if (! $botId) {
            return null;
        }

        $bot = Bots::query()->find($botId);

        if (! $bot || empty($bot->api_token)) {
            Log::warning('指定的机器人不存在或没有 token', ['bot_id' => $botId]);

            return null;
        }

        return $this->botApiFactory->forBot($bot);
    }
}
