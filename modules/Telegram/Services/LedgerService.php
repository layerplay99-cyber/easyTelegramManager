<?php

namespace Modules\Telegram\Services;

use Modules\Telegram\Models\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * 账本服务类
 */
class LedgerService
{
    /**
     * 获取今日账单
     * @param int $memberId 会员ID
     * @param string|null $currency 币种（可选）
     * @return array
     */
    public function getTodayBill(int $memberId, ?string $currency = null): array
    {
        $today = date('Y-m-d');
        return $this->getDateBill($memberId, $today, $currency);
    }

    /**
     * 获取指定日期账单
     * @param int $memberId 会员ID
     * @param string $date 日期（Y-m-d）
     * @param string|null $currency 币种（可选）
     * @return array
     */
    public function getDateBill(int $memberId, string $date, ?string $currency = null): array
    {
        $startTime = strtotime($date . ' 00:00:00');
        $endTime = strtotime($date . ' 23:59:59');

        $query = Ledger::where('member_id', $memberId)
            ->whereBetween('created_at', [$startTime, $endTime]);

        if ($currency) {
            $query->where('currency', $currency);
        }

        $ledgers = $query->orderBy('created_at', 'desc')->get();

        // 统计
        $totalIncome = $ledgers->where('amount', '>', 0)->sum('amount');
        $totalExpense = abs($ledgers->where('amount', '<', 0)->sum('amount'));
        $netAmount = $totalIncome - $totalExpense;

        return [
            'date' => $date,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net_amount' => $netAmount,
            'count' => $ledgers->count(),
            'items' => $ledgers->toArray(),
        ];
    }

    /**
     * 获取月度账单
     * @param int $memberId 会员ID
     * @param string $month 月份（Y-m）
     * @param string|null $currency 币种（可选）
     * @return array
     */
    public function getMonthBill(int $memberId, string $month, ?string $currency = null): array
    {
        $startTime = strtotime($month . '-01 00:00:00');
        $endTime = strtotime(date('Y-m-t 23:59:59', $startTime));

        $query = Ledger::where('member_id', $memberId)
            ->whereBetween('created_at', [$startTime, $endTime]);

        if ($currency) {
            $query->where('currency', $currency);
        }

        $ledgers = $query->orderBy('created_at', 'desc')->get();

        // 按日期分组统计
        // 注意：CatchModel 把 created_at cast 成了 datetime（Carbon 对象），
        // 直接 date('Y-m-d', $carbon) 在 PHP 8 下会抛 TypeError。
        $dailyStats = $ledgers->groupBy(function($item) {
            $createdAt = $item->created_at;

            if ($createdAt instanceof \Carbon\CarbonInterface) {
                return $createdAt->format('Y-m-d');
            }

            return date('Y-m-d', (int) $createdAt);
        })->map(function($dayLedgers) {
            return [
                'income' => $dayLedgers->where('amount', '>', 0)->sum('amount'),
                'expense' => abs($dayLedgers->where('amount', '<', 0)->sum('amount')),
                'count' => $dayLedgers->count(),
            ];
        });

        // 总统计
        $totalIncome = $ledgers->where('amount', '>', 0)->sum('amount');
        $totalExpense = abs($ledgers->where('amount', '<', 0)->sum('amount'));

        return [
            'month' => $month,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net_amount' => $totalIncome - $totalExpense,
            'daily_stats' => $dailyStats,
            'count' => $ledgers->count(),
        ];
    }

    /**
     * 获取账单列表（分页）
     * @param int $memberId 会员ID
     * @param array $filters 过滤条件
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @return array
     */
    public function getBillList(int $memberId, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = Ledger::where('member_id', $memberId);

        // 币种筛选
        if (isset($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        // 类型筛选
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // 日期范围筛选
        if (isset($filters['start_date'])) {
            $startTime = strtotime($filters['start_date'] . ' 00:00:00');
            $query->where('created_at', '>=', $startTime);
        }

        if (isset($filters['end_date'])) {
            $endTime = strtotime($filters['end_date'] . ' 23:59:59');
            $query->where('created_at', '<=', $endTime);
        }

        // 金额筛选（收入/支出）
        if (isset($filters['amount_type'])) {
            if ($filters['amount_type'] === 'income') {
                $query->where('amount', '>', 0);
            } elseif ($filters['amount_type'] === 'expense') {
                $query->where('amount', '<', 0);
            }
        }

        // 总数
        $total = $query->count();

        // pageSize 可能为 0 或负数 → 除零
        $pageSize = max(1, min((int) $pageSize, 100));
        $page = max(1, (int) $page);

        // 分页
        $offset = ($page - 1) * $pageSize;
        $ledgers = $query->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($pageSize)
            ->get();

        return [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => ceil($total / $pageSize),
            'items' => $ledgers->toArray(),
        ];
    }

    /**
     * 按类型统计
     * @param int $memberId 会员ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return array
     */
    public function getStatsByType(int $memberId, string $startDate, string $endDate): array
    {
        $startTime = strtotime($startDate . ' 00:00:00');
        $endTime = strtotime($endDate . ' 23:59:59');

        $stats = Ledger::where('member_id', $memberId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->select('type', 'currency', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as count'))
            ->groupBy('type', 'currency')
            ->get();

        return $stats->toArray();
    }
}

