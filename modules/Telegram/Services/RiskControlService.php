<?php

namespace Modules\Telegram\Services;

use Modules\Telegram\Models\Member;
use Modules\Telegram\Models\RiskControlRule;
use Modules\Telegram\Models\RiskControlLog;
use Modules\Telegram\Models\TransactionLimit;
use Modules\Telegram\Models\RechargeOrder;
use Modules\Telegram\Models\WithdrawOrder;
use Illuminate\Support\Facades\DB;

/**
 * 风控服务类
 */
class RiskControlService
{
    /**
     * 检查充值风控
     * @param Member $member 会员
     * @param float $amount 金额
     * @param string $currency 币种
     * @return array ['passed' => bool, 'message' => string, 'auto_audit' => bool]
     */
    public function checkRecharge(Member $member, float $amount, string $currency): array
    {
        // 1. 检查交易限额
        $limitCheck = $this->checkTransactionLimit($member, 'recharge', $amount, $currency);
        if (!$limitCheck['passed']) {
            return $limitCheck;
        }

        // 2. 检查风控规则
        $rules = RiskControlRule::where('type', 'recharge')
            ->where('status', 1)
            ->orderBy('priority', 'desc')
            ->get();

        foreach ($rules as $rule) {
            $ruleCheck = $this->evaluateRule($rule, $member, $amount, $currency, 'recharge');

            if ($ruleCheck['triggered']) {
                // 记录风控日志
                $this->logRiskControl($member, $rule, null, 'recharge', $ruleCheck);

                // 根据动作处理
                switch ($rule->action) {
                    case 'reject':
                        return [
                            'passed' => false,
                            'message' => '触发风控规则，交易被拒绝',
                            'auto_audit' => false,
                        ];

                    case 'manual_audit':
                        return [
                            'passed' => true,
                            'message' => '需要人工审核',
                            'auto_audit' => false,
                        ];

                    case 'freeze':
                        $member->status = Member::STATUS_FROZEN;
                        $member->save();
                        return [
                            'passed' => false,
                            'message' => '账户已被冻结，请联系客服',
                            'auto_audit' => false,
                        ];
                }
            }
        }

        return [
            'passed' => true,
            'message' => '风控检查通过',
            'auto_audit' => true,
        ];
    }

    /**
     * 检查提现风控
     * @param Member $member 会员
     * @param float $amount 金额
     * @param string $currency 币种
     * @return array
     */
    public function checkWithdraw(Member $member, float $amount, string $currency): array
    {
        // 1. 检查交易限额
        $limitCheck = $this->checkTransactionLimit($member, 'withdraw', $amount, $currency);
        if (!$limitCheck['passed']) {
            return $limitCheck;
        }

        // 2. 检查提现条件
        // 检查是否有未完成的提现订单
        $pendingCount = WithdrawOrder::where('member_id', $member->id)
            ->whereIn('status', [
                WithdrawOrder::STATUS_PENDING,
                WithdrawOrder::STATUS_PROCESSING
            ])
            ->count();

        if ($pendingCount > 0) {
            return [
                'passed' => false,
                'message' => '您有未完成的提现订单，请等待处理完成',
                'auto_audit' => false,
            ];
        }

        // 3. 检查充值提现比例（防洗钱）
        $totalRecharge = RechargeOrder::where('member_id', $member->id)
            ->where('status', RechargeOrder::STATUS_COMPLETED)
            ->sum('actual_amount');

        $totalWithdraw = WithdrawOrder::where('member_id', $member->id)
            ->where('status', WithdrawOrder::STATUS_COMPLETED)
            ->sum('amount');

        // 提现总额不能超过充值总额的150%
        if ($totalWithdraw + $amount > $totalRecharge * 1.5) {
            return [
                'passed' => false,
                'message' => '提现金额超过限制',
                'auto_audit' => false,
            ];
        }

        // 4. 检查风控规则
        $rules = RiskControlRule::where('type', 'withdraw')
            ->where('status', 1)
            ->orderBy('priority', 'desc')
            ->get();

        $needManualAudit = false;

        foreach ($rules as $rule) {
            $ruleCheck = $this->evaluateRule($rule, $member, $amount, $currency, 'withdraw');

            if ($ruleCheck['triggered']) {
                // 记录风控日志
                $this->logRiskControl($member, $rule, null, 'withdraw', $ruleCheck);

                // 根据动作处理
                switch ($rule->action) {
                    case 'reject':
                        return [
                            'passed' => false,
                            'message' => '触发风控规则，提现被拒绝',
                            'auto_audit' => false,
                            'allow_manual_audit' => false,
                        ];

                    case 'manual_audit':
                        $needManualAudit = true;
                        break;

                    case 'freeze':
                        $member->status = Member::STATUS_FROZEN;
                        $member->save();
                        return [
                            'passed' => false,
                            'message' => '账户已被冻结，请联系客服',
                            'auto_audit' => false,
                            'allow_manual_audit' => false,
                        ];
                }
            }
        }

        return [
            'passed' => true,
            'message' => '风控检查通过',
            'auto_audit' => !$needManualAudit,
            'allow_manual_audit' => true,
        ];
    }

    /**
     * 检查交易限额
     * @param Member $member 会员
     * @param string $type 类型
     * @param float $amount 金额
     * @param string $currency 币种
     * @return array
     */
    protected function checkTransactionLimit(Member $member, string $type, float $amount, string $currency): array
    {
        // 获取会员等级（这里简化处理，默认为default）
        $level = 'default';

        $limit = TransactionLimit::where('type', $type)
            ->where('level', $level)
            ->where('currency', $currency)
            ->where('status', 1)
            ->first();

        if (!$limit) {
            return ['passed' => true, 'message' => ''];
        }

        // 检查单笔限额
        if ($limit->min_amount > 0 && $amount < $limit->min_amount) {
            return [
                'passed' => false,
                'message' => "单笔最小金额为 {$limit->min_amount}",
                'auto_audit' => false,
            ];
        }

        if ($limit->max_amount > 0 && $amount > $limit->max_amount) {
            return [
                'passed' => false,
                'message' => "单笔最大金额为 {$limit->max_amount}",
                'auto_audit' => false,
            ];
        }

        // 检查每日限额
        if ($limit->daily_amount > 0 || $limit->daily_count > 0) {
            $today = date('Y-m-d');
            $orderModel = $type === 'recharge' ? RechargeOrder::class : WithdrawOrder::class;

            // 注意：created_at 是 unix 时间戳(int)，whereDate 会生成
            // DATE(created_at) 比较，DATE(1770000000) 恒为 NULL → 每日限额完全失效。
            // 这里和下面的月度统计保持一致，用 FROM_UNIXTIME 转换后再比较。
            $dailyStats = $orderModel::where('member_id', $member->id)
                ->where(DB::raw('DATE_FORMAT(FROM_UNIXTIME(created_at), "%Y-%m-%d")'), $today)
                ->where('status', '!=', 3) // 排除已取消
                ->selectRaw('COUNT(*) as count, SUM(amount) as total')
                ->first();

            // SUM 在无记录时返回 null，直接参与比较会出问题
            $dailyCount = (int) ($dailyStats->count ?? 0);
            $dailyTotal = (float) ($dailyStats->total ?? 0);

            if ($limit->daily_count > 0 && $dailyCount >= $limit->daily_count) {
                return [
                    'passed' => false,
                    'message' => "每日最多{$type}次数为 {$limit->daily_count} 次",
                    'auto_audit' => false,
                ];
            }

            if ($limit->daily_amount > 0 && ($dailyTotal + $amount) > $limit->daily_amount) {
                return [
                    'passed' => false,
                    'message' => "每日{$type}限额为 {$limit->daily_amount}",
                    'auto_audit' => false,
                ];
            }
        }

        // 检查每月限额
        if ($limit->monthly_amount > 0 || $limit->monthly_count > 0) {
            $month = date('Y-m');
            $orderModel = $type === 'recharge' ? RechargeOrder::class : WithdrawOrder::class;

            $monthlyStats = $orderModel::where('member_id', $member->id)
                ->where(DB::raw('DATE_FORMAT(FROM_UNIXTIME(created_at), "%Y-%m")'), $month)
                ->where('status', '!=', 3)
                ->selectRaw('COUNT(*) as count, SUM(amount) as total')
                ->first();

            $monthlyCount = (int) ($monthlyStats->count ?? 0);
            $monthlyTotal = (float) ($monthlyStats->total ?? 0);

            if ($limit->monthly_count > 0 && $monthlyCount >= $limit->monthly_count) {
                return [
                    'passed' => false,
                    'message' => "每月最多{$type}次数为 {$limit->monthly_count} 次",
                    'auto_audit' => false,
                ];
            }

            if ($limit->monthly_amount > 0 && ($monthlyTotal + $amount) > $limit->monthly_amount) {
                return [
                    'passed' => false,
                    'message' => "每月{$type}限额为 {$limit->monthly_amount}",
                    'auto_audit' => false,
                ];
            }
        }

        return ['passed' => true, 'message' => ''];
    }

    /**
     * 评估风控规则
     * @param RiskControlRule $rule 规则
     * @param Member $member 会员
     * @param float $amount 金额
     * @param string $currency 币种
     * @param string $type 类型
     * @return array
     */
    protected function evaluateRule(RiskControlRule $rule, Member $member, float $amount, string $currency, string $type): array
    {
        // RiskControlRule 有 getConditionsAttribute()，取出来已经是数组了，
        // 再 json_decode 会直接 TypeError（array given）—— 充值/提现下单必炸。
        $conditions = is_array($rule->conditions)
            ? $rule->conditions
            : (json_decode((string) $rule->conditions, true) ?: []);
        $triggered = false;
        $triggerData = [];

        // 检查单笔金额
        if (isset($conditions['single_amount']) && $amount >= $conditions['single_amount']) {
            $triggered = true;
            $triggerData['single_amount'] = [
                'threshold' => $conditions['single_amount'],
                'actual' => $amount,
            ];
        }

        // 检查每日金额
        if (isset($conditions['daily_amount'])) {
            // created_at 是 int 时间戳，whereDate() 作用在整型列上恒不成立，
            // 日累计/日笔数的风控条件会永远不触发。改成按时间戳区间查。
            $dayStart = strtotime(date('Y-m-d 00:00:00'));
            $dayEnd = $dayStart + 86400;
            $orderModel = $type === 'recharge' ? RechargeOrder::class : WithdrawOrder::class;

            $dailyAmount = $orderModel::where('member_id', $member->id)
                ->where('created_at', '>=', $dayStart)
                ->where('created_at', '<', $dayEnd)
                ->where('status', '!=', 3)
                ->sum('amount');

            if ($dailyAmount + $amount >= $conditions['daily_amount']) {
                $triggered = true;
                $triggerData['daily_amount'] = [
                    'threshold' => $conditions['daily_amount'],
                    'actual' => $dailyAmount + $amount,
                ];
            }
        }

        // 检查每日次数
        if (isset($conditions['daily_count'])) {
            // created_at 是 int 时间戳，whereDate() 作用在整型列上恒不成立，
            // 日累计/日笔数的风控条件会永远不触发。改成按时间戳区间查。
            $dayStart = strtotime(date('Y-m-d 00:00:00'));
            $dayEnd = $dayStart + 86400;
            $orderModel = $type === 'recharge' ? RechargeOrder::class : WithdrawOrder::class;

            $dailyCount = $orderModel::where('member_id', $member->id)
                ->where('created_at', '>=', $dayStart)
                ->where('created_at', '<', $dayEnd)
                ->where('status', '!=', 3)
                ->count();

            if ($dailyCount + 1 >= $conditions['daily_count']) {
                $triggered = true;
                $triggerData['daily_count'] = [
                    'threshold' => $conditions['daily_count'],
                    'actual' => $dailyCount + 1,
                ];
            }
        }

        // 检查IP限制
        if (isset($conditions['ip_limit'])) {
            $ip = request()->ip();
            // created_at 是 int 时间戳，whereDate() 作用在整型列上恒不成立，
            // 日累计/日笔数的风控条件会永远不触发。改成按时间戳区间查。
            $dayStart = strtotime(date('Y-m-d 00:00:00'));
            $dayEnd = $dayStart + 86400;
            $orderModel = $type === 'recharge' ? RechargeOrder::class : WithdrawOrder::class;

            $ipCount = $orderModel::where('request_ip', $ip)
                ->where('created_at', '>=', $dayStart)
                ->where('created_at', '<', $dayEnd)
                ->distinct('member_id')
                ->count('member_id');

            if ($ipCount >= $conditions['ip_limit']) {
                $triggered = true;
                $triggerData['ip_limit'] = [
                    'threshold' => $conditions['ip_limit'],
                    'actual' => $ipCount,
                    'ip' => $ip,
                ];
            }
        }

        return [
            'triggered' => $triggered,
            'trigger_data' => $triggerData,
        ];
    }

    /**
     * 记录风控日志
     */
    protected function logRiskControl(Member $member, RiskControlRule $rule, ?string $orderNo, string $type, array $checkResult): void
    {
        RiskControlLog::create([
            'member_id' => $member->id,
            'rule_id' => $rule->id,
            'order_no' => $orderNo,
            'type' => $type,
            'risk_level' => $rule->risk_level,
            'trigger_data' => json_encode($checkResult['trigger_data'] ?? []),
            'action' => $rule->action,
            'status' => 0,
            'ip' => request()->ip(),
        ]);
    }
}
