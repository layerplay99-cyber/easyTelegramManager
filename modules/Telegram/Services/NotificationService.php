<?php

namespace Modules\Telegram\Services;

use Modules\Telegram\Models\Member;
use Modules\Telegram\Models\RechargeOrder;
use Modules\Telegram\Models\WithdrawOrder;
use Modules\Telegram\Models\Notification;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Telegram\Bot\Api;
use Telegram\Bot\Keyboard\Keyboard;
use Illuminate\Support\Facades\Log;

/**
 * 通知服务类
 */
class NotificationService
{
    protected ?Api $telegram = null;

    public function __construct()
    {
        // 统一通过 BotApiFactory 获取客户端（token 来自 config('telegram.bot_token')）
        try {
            $this->telegram = app(BotApiFactory::class)->default();
        } catch (\Exception $e) {
            Log::error('Telegram Bot 初始化失败: ' . $e->getMessage());
        }
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
        $message .= "⏰ 时间：" . date('Y-m-d H:i:s', $order->created_at) . "\n";

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
        return $this->sendToTelegram($member->telegram_user_id, $message);
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
        $message .= "⏰ 时间：" . date('Y-m-d H:i:s', $order->created_at) . "\n";
        $message .= "\n正在处理中，请耐心等待。";

        $this->saveNotification(
            $member->id,
            'withdraw',
            '提现申请已提交',
            $message,
            'WithdrawOrder',
            $order->id
        );

        return $this->sendToTelegram($member->telegram_user_id, $message);
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

        return $this->sendToTelegram($member->telegram_user_id, $message);
    }

    /**
     * 发送到Telegram群或私聊
     * @param int $chatId 聊天ID或用户ID
     * @param string $message 消息内容
     * @param array $keyboard 键盘（可选）
     * @return bool
     */
    public function sendToTelegram(int $chatId, string $message, array $keyboard = []): bool
    {
        if (!$this->telegram) {
            Log::warning('Telegram Bot 未初始化，无法发送消息');
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

            $this->telegram->sendMessage($params);

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
     * @return bool
     */
    public function sendBillNotification(Member $member, string $period, string $date = ''): bool
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

        return $this->sendToTelegram($member->telegram_user_id, $message);
    }

    /**
     * 发送余额通知
     * @param Member $member
     * @return bool
     */
    public function sendBalanceNotification(Member $member): bool
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

        return $this->sendToTelegram($member->telegram_user_id, $message);
    }
}
