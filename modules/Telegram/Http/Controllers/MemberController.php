<?php

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController;
use Modules\Telegram\Models\Member;
use Modules\Telegram\Models\OperationLog;
use Modules\Telegram\Http\Requests\Member\MemberIndexRequest;
use Modules\Telegram\Http\Requests\Member\UpdateStatusRequest;
use Modules\Telegram\Http\Requests\Member\ResetPaymentPasswordRequest;
use Illuminate\Http\Request;

class MemberController extends CatchController
{
    protected $model;

    public function __construct(Member $member)
    {
        $this->model = $member;
    }

    /**
     * 会员列表
     */
    public function index(MemberIndexRequest $request)
    {
        $query = $this->model->newQuery();

        // 使用Request中处理后的搜索参数
        $params = $request->getSearchParams();

        foreach ($params as $key => $value) {
            if ($key === 'telegram_username') {
                $query->where($key, 'like', '%' . $value . '%');
            } elseif ($key === 'created_at') {
                $query->whereBetween($key, $value);
            } else {
                $query->where($key, $value);
            }
        }

        return $this->success($query->orderByDesc('id')->paginate($request->get('limit', 20)));
    }

    /**
     * 会员详情
     */
    public function show($id)
    {
        $member = $this->model->with(['wallets', 'rechargeOrders', 'withdrawOrders'])->findOrFail($id);

        return $this->success($member);
    }

    /**
     * 更新会员状态
     */
    public function updateStatus(UpdateStatusRequest $request, $id)
    {
        $member = $this->model->findOrFail($id);
        $member->status = $request->status;

        if ($request->has('remark')) {
            $member->remark = $request->remark;
        }

        $member->save();

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'member',
            'action' => 'update_status',
            'related_type' => 'Member',
            'related_id' => $member->id,
            'params' => $request->all(),
        ]);

        return $this->success($member, '状态更新为：' . $request->getStatusText());
    }

    /**
     * 重置支付密码
     */
    public function resetPaymentPassword(ResetPaymentPasswordRequest $request, $id)
    {
        $member = $this->model->findOrFail($id);
        $member->setPaymentPassword($request->password);

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'member',
            'action' => 'reset_payment_password',
            'related_type' => 'Member',
            'related_id' => $member->id,
        ]);

        return $this->success(null, '支付密码重置成功');
    }

    /**
     * 会员统计
     */
    public function statistics($id)
    {
        $member = $this->model->findOrFail($id);

        $stats = [
            'total_recharge' => $member->rechargeOrders()
                ->where('status', \Modules\Telegram\Models\RechargeOrder::STATUS_COMPLETED)
                ->sum('actual_amount'),
            'total_withdraw' => $member->withdrawOrders()
                ->where('status', \Modules\Telegram\Models\WithdrawOrder::STATUS_COMPLETED)
                ->sum('amount'),
            'recharge_count' => $member->rechargeOrders()
                ->where('status', \Modules\Telegram\Models\RechargeOrder::STATUS_COMPLETED)
                ->count(),
            'withdraw_count' => $member->withdrawOrders()
                ->where('status', \Modules\Telegram\Models\WithdrawOrder::STATUS_COMPLETED)
                ->count(),
            'pending_recharge' => $member->rechargeOrders()
                ->where('status', \Modules\Telegram\Models\RechargeOrder::STATUS_PENDING)
                ->count(),
            'pending_withdraw' => $member->withdrawOrders()
                ->whereIn('status', [
                    \Modules\Telegram\Models\WithdrawOrder::STATUS_PENDING,
                    \Modules\Telegram\Models\WithdrawOrder::STATUS_PROCESSING
                ])
                ->count(),
        ];

        return $this->success($stats);
    }

    /**
     * 会员账单
     */
    public function ledgers(Request $request, $id)
    {
        $member = $this->model->findOrFail($id);

        $query = $member->ledgers()->with('wallet');

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('currency')) {
            $query->where('currency', $request->currency);
        }

        if ($request->has('created_at')) {
            $query->whereBetween('created_at', $request->created_at);
        }

        return $this->success($query->orderByDesc('id')->paginate($request->get('limit', 20)));
    }
}
