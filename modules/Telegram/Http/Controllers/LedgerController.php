<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Telegram\Models\Ledger;

/**
 * 账本流水（只读）
 *
 * 账本只由 Wallet 的加/减/冻结/解冻方法写入，这里只提供查询，
 * 不提供任何新增/修改/删除入口——防止有人直接改流水。
 */
class LedgerController extends BaseController
{
    public function index(Request $request)
    {
        $query = Ledger::query()
            ->with(['member:id,telegram_user_id,telegram_username'])
            ->when($request->get('member_id'), fn ($q, $v) => $q->where('member_id', $v))
            ->when($request->get('telegram_user_id'), function ($q, $v) {
                $q->whereHas('member', fn ($m) => $m->where('telegram_user_id', $v));
            })
            ->when($request->get('currency'), fn ($q, $v) => $q->where('currency', $v))
            ->when($request->get('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->get('order_no'), fn ($q, $v) => $q->where('order_no', $v))
            ->when($request->get('start_time'), fn ($q, $v) => $q->where('created_at', '>=', strtotime($v)))
            ->when($request->get('end_time'), fn ($q, $v) => $q->where('created_at', '<=', strtotime($v)));

        return $this->success($query->orderByDesc('id')->paginate($request->get('limit', 20)));
    }

    /**
     * 账本类型下拉
     */
    public function types()
    {
        $types = [
            'recharge' => '充值',
            'withdraw' => '提现',
            'freeze' => '冻结',
            'unfreeze' => '解冻',
            'reward' => '奖励',
            'deduct' => '扣款',
            'transfer_in' => '转入',
            'transfer_out' => '转出',
            'adjust' => '调账',
        ];

        return $this->success(collect($types)->map(fn ($label, $value) => [
            'value' => $value,
            'label' => $label,
        ])->values());
    }
}
