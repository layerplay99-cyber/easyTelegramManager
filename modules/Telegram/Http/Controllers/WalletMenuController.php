<?php

namespace Modules\Telegram\Http\Controllers;

use Modules\Telegram\Models\Member;
use Modules\Telegram\Models\ExchangeRate;
use Modules\Telegram\Services\RechargeService;
use Modules\Telegram\Services\WithdrawService;
use Modules\Telegram\Services\NotificationService;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Modules\Telegram\Services\LedgerService;
use Telegram\Bot\Api;
use Telegram\Bot\Keyboard\Keyboard;
use Illuminate\Support\Facades\Log;

/**
 * Telegram钱包菜单处理器
 * 处理充值、提现、账单查询等交互
 */
class WalletMenuController
{
    protected Api $telegram;
    protected RechargeService $rechargeService;
    protected WithdrawService $withdrawService;
    protected NotificationService $notificationService;
    protected LedgerService $ledgerService;

    public function __construct(
        RechargeService $rechargeService,
        WithdrawService $withdrawService,
        NotificationService $notificationService,
        LedgerService $ledgerService,
        BotApiFactory $botApiFactory
    ) {
        $this->telegram = $botApiFactory->default();
        $this->rechargeService = $rechargeService;
        $this->withdrawService = $withdrawService;
        $this->notificationService = $notificationService;
        $this->ledgerService = $ledgerService;
    }

    /**
     * 处理/wallet命令 - 显示主菜单
     */
    public function showMainMenu($update)
    {
        $chatId = $update->getMessage()->getChat()->getId();
        $userId = $update->getMessage()->getFrom()->getId();

        // 获取或创建会员
        $member = $this->getOrCreateMember($update->getMessage()->getFrom());

        $keyboard = [
            [
                ['text' => '💰 创建账户', 'callback_data' => 'wallet_create'],
                ['text' => '💵 查询余额', 'callback_data' => 'wallet_balance'],
            ],
            [
                ['text' => '💳 充值', 'callback_data' => 'wallet_recharge'],
                ['text' => '💸 提现', 'callback_data' => 'wallet_withdraw'],
            ],
            [
                ['text' => '📊 今日账单', 'callback_data' => 'wallet_bill_today'],
                ['text' => '📅 查询账单', 'callback_data' => 'wallet_bill_date'],
            ],
            [
                ['text' => '❌ 关闭', 'callback_data' => 'wallet_close'],
            ],
        ];

        $message = "💼 <b>钱包管理菜单</b>\n\n";
        $message .= "👤 会员：{$member->username}\n";
        $message .= "📱 请选择操作：\n";

        $this->sendHtmlMessage($chatId, $message, $keyboard);
    }

    /**
     * 处理回调查询
     */
    public function handleCallback($update)
    {
        $callbackQuery = $update->getCallbackQuery();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $messageId = $callbackQuery->getMessage()->getMessageId();
        $data = $callbackQuery->getData();
        $userId = $callbackQuery->getFrom()->getId();

        // 获取会员
        $member = Member::where('telegram_user_id', $userId)->first();
        if (!$member) {
            $this->answerCallback($callbackQuery->getId(), '请先创建账户', true);
            return;
        }

        // 根据callback_data分发处理
        switch ($data) {
            case 'wallet_create':
                $this->handleCreateAccount($callbackQuery, $member);
                break;

            case 'wallet_balance':
                $this->handleQueryBalance($callbackQuery, $member);
                break;

            case 'wallet_recharge':
                $this->handleRecharge($callbackQuery, $member);
                break;

            case 'wallet_withdraw':
                $this->handleWithdraw($callbackQuery, $member);
                break;

            case 'wallet_bill_today':
                $this->handleTodayBill($callbackQuery, $member);
                break;

            case 'wallet_bill_date':
                $this->handleDateBill($callbackQuery, $member);
                break;

            case 'wallet_close':
                $this->telegram->deleteMessage([
                    'chat_id' => $chatId,
                    'message_id' => $messageId
                ]);
                break;

            default:
                // 处理其他回调（如币种选择、汇率选择等）
                $this->handleDynamicCallback($callbackQuery, $member, $data);
                break;
        }
    }

    /**
     * 统一回复回调查询
     */
    protected function answerCallback(string $callbackQueryId, string $text = '操作成功', bool $showAlert = false): void
    {
        $this->telegram->answerCallbackQuery([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
        ]);
    }

    /**
     * 统一发送 HTML 消息（可选内联键盘）
     */
    protected function sendHtmlMessage(int|string $chatId, string $text, ?array $keyboard = null): void
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard !== null) {
            $params['reply_markup'] = Keyboard::make([
                'inline_keyboard' => $keyboard
            ]);
        }

        $this->telegram->sendMessage($params);
    }

    /**
     * 创建账户
     */
    protected function handleCreateAccount($callbackQuery, $member)
    {
        $chatId = $callbackQuery->getMessage()->getChat()->getId();

        if ($member->wallets()->count() > 0) {
            $message = "✅ 您已经有账户了\n\n";
            $wallets = $member->wallets;
            foreach ($wallets as $wallet) {
                $message .= "💰 {$wallet->currency} 钱包\n";
                $message .= "   余额：{$wallet->balance}\n";
            }
        } else {
            // 创建默认CNY钱包
            $member->wallets()->create([
                'currency' => 'CNY',
                'balance' => 0,
                'frozen_balance' => 0,
                'status' => 1,
            ]);

            $message = "🎉 账户创建成功！\n\n";
            $message .= "💰 已为您创建 CNY 钱包\n";
            $message .= "现在可以开始充值和提现了。";
        }

        $this->answerCallback($callbackQuery->getId());
        $this->sendHtmlMessage($chatId, $message);
    }

    /**
     * 查询余额
     */
    protected function handleQueryBalance($callbackQuery, $member)
    {
        $this->answerCallback($callbackQuery->getId());
        $this->notificationService->sendBalanceNotification($member);
    }

    /**
     * 充值
     */
    protected function handleRecharge($callbackQuery, $member)
    {
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $this->answerCallback($callbackQuery->getId());

        $keyboard = [
            [
                ['text' => 'CNY 人民币', 'callback_data' => 'recharge_currency_CNY'],
                ['text' => 'VND 越南盾', 'callback_data' => 'recharge_currency_VND'],
            ],
            [
                ['text' => 'USDT', 'callback_data' => 'recharge_currency_USDT'],
                ['text' => '❌ 返回', 'callback_data' => 'wallet_back'],
            ],
        ];

        $message = "💳 <b>充值</b>\n\n请选择充值币种：";
        $this->sendHtmlMessage($chatId, $message, $keyboard);
    }

    /**
     * 提现
     */
    protected function handleWithdraw($callbackQuery, $member)
    {
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $this->answerCallback($callbackQuery->getId());

        // 检查实名认证
        if (!$member->isVerified()) {
            $this->sendHtmlMessage($chatId, "❌ 请先完成实名认证才能提现");
            return;
        }

        // 检查支付密码
        if (empty($member->payment_password)) {
            $this->sendHtmlMessage($chatId, "❌ 请先设置支付密码才能提现");
            return;
        }

        // 显示币种和汇率选择
        $rates = ExchangeRate::where('status', 1)->get();

        $keyboard = [];
        foreach ($rates as $rate) {
            $keyboard[] = [
                ['text' => "{$rate->to_currency} (1:{$rate->sell_rate})", 'callback_data' => "withdraw_rate_{$rate->id}"]
            ];
        }
        $keyboard[] = [['text' => '❌ 返回', 'callback_data' => 'wallet_back']];

        $message = "💸 <b>提现</b>\n\n请选择提现币种和汇率：\n（汇率会实时更新）";
        $this->sendHtmlMessage($chatId, $message, $keyboard);
    }

    /**
     * 今日账单
     */
    protected function handleTodayBill($callbackQuery, $member)
    {
        $this->answerCallback($callbackQuery->getId());
        $this->notificationService->sendBillNotification($member, 'today');
    }

    /**
     * 指定日期账单
     */
    protected function handleDateBill($callbackQuery, $member)
    {
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $this->answerCallback($callbackQuery->getId());

        $message = "📅 <b>查询账单</b>\n\n请发送日期（格式：YYYY-MM-DD）\n例如：2025-01-27";
        $this->sendHtmlMessage($chatId, $message);

        // TODO: 设置会话状态，等待用户输入日期
    }

    /**
     * 处理动态回调（币种选择、汇率选择等）
     */
    protected function handleDynamicCallback($callbackQuery, $member, $data)
    {
        // 充值币种选择
        if (strpos($data, 'recharge_currency_') === 0) {
            $currency = str_replace('recharge_currency_', '', $data);
            $this->handleRechargeCurrency($callbackQuery, $member, $currency);
            return;
        }

        // 提现汇率选择
        if (strpos($data, 'withdraw_rate_') === 0) {
            $rateId = str_replace('withdraw_rate_', '', $data);
            $this->handleWithdrawRate($callbackQuery, $member, $rateId);
            return;
        }

        $this->answerCallback($callbackQuery->getId(), '未知操作');
    }

    /**
     * 处理充值币种选择
     */
    protected function handleRechargeCurrency($callbackQuery, $member, $currency)
    {
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $this->answerCallback($callbackQuery->getId());

        $miniAppUrl = config('app.url') . "/miniapp/recharge?member_id={$member->id}&currency={$currency}";

        $keyboard = [
            [
                ['text' => '🔗 打开充值页面', 'url' => $miniAppUrl]
            ],
            [
                ['text' => '❌ 取消', 'callback_data' => 'wallet_close']
            ]
        ];

        $message = "💳 <b>充值 - {$currency}</b>\n\n请点击下方按钮打开充值页面\n填写充值信息后提交，系统会自动处理。\n\n⚠️ 请确保填写正确的信息";
        $this->sendHtmlMessage($chatId, $message, $keyboard);
    }

    /**
     * 处理提现汇率选择
     */
    protected function handleWithdrawRate($callbackQuery, $member, $rateId)
    {
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $this->answerCallback($callbackQuery->getId());

        $rate = ExchangeRate::find($rateId);
        if (!$rate) {
            $this->sendHtmlMessage($chatId, '❌ 汇率不存在');
            return;
        }

        $miniAppUrl = config('app.url') . "/miniapp/withdraw?member_id={$member->id}&rate_id={$rateId}";

        $keyboard = [
            [
                ['text' => '🔗 打开提现页面', 'url' => $miniAppUrl]
            ],
            [
                ['text' => '❌ 取消', 'callback_data' => 'wallet_close']
            ]
        ];

        $message = "💸 <b>提现 - {$rate->to_currency}</b>\n\n当前汇率：1:{$rate->sell_rate}\n\n请点击下方按钮打开提现页面\n填写提现信息后提交，将进入审核流程。\n\n⚠️ 提现需要支付密码验证";
        $this->sendHtmlMessage($chatId, $message, $keyboard);
    }

    /**
     * 获取或创建会员
     */
    protected function getOrCreateMember($from)
    {
        $member = Member::where('telegram_user_id', $from->getId())->first();

        if (!$member) {
            $member = Member::create([
                'username' => $from->getUsername() ?? 'user_' . $from->getId(),
                'telegram_user_id' => $from->getId(),
                'telegram_username' => $from->getUsername(),
                'nickname' => $from->getFirstName() . ' ' . $from->getLastName(),
                'status' => Member::STATUS_NORMAL,
                'register_ip' => request()->ip(),
            ]);
        }

        return $member;
    }
}

