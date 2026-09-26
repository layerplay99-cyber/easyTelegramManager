<?php

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController;
use Modules\Telegram\Models\RechargeOrder;
use Modules\Telegram\Models\OperationLog;
use Modules\Telegram\Services\RechargeService;
use Modules\Telegram\Http\Requests\Order\RechargeOrderIndexRequest;
use Modules\Telegram\Http\Requests\Order\CompleteOrderRequest;
use Modules\Telegram\Http\Requests\Order\CancelOrderRequest;
use Illuminate\Http\Request;

class RechargeOrderController extends CatchController
{
    protected $model;
    protected $rechargeService;

    public function __construct(RechargeOrder $rechargeOrder, RechargeService $rechargeService)
    {
        $this->model = $rechargeOrder;
        $this->rechargeService = $rechargeService;
    }

    /**
     * 充值订单列表
     */
    public function index(RechargeOrderIndexRequest $request)
    {
        $query = $this->model->with(['member', 'channel']);

        // 搜索条件
        if ($request->has('order_no')) {
            $query->where('order_no', 'like', '%' . $request->order_no . '%');
        }

        if ($request->has('member_id')) {
            $query->where('member_id', $request->member_id);
        }

        if ($request->has('channel_id')) {
            $query->where('channel_id', $request->channel_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('currency')) {
            $query->where('currency', $request->currency);
        }

        if ($request->has('third_order_no')) {
            $query->where('third_order_no', $request->third_order_no);
        }

        // 时间范围
        if ($request->has('created_at')) {
            $query->whereBetween('created_at', $request->created_at);
        }

        // 金额范围
        if ($request->has('amount_min')) {
            $query->where('amount', '>=', $request->amount_min);
        }
        if ($request->has('amount_max')) {
            $query->where('amount', '<=', $request->amount_max);
        }

        return $this->success($query->orderByDesc('id')->paginate($request->get('limit', 20)));
    }

    /**
     * 订单详情
     */
    public function show($id)
    {
        $order = $this->model->with(['member', 'channel'])->findOrFail($id);

        return $this->success($order);
    }

    /**
     * 手动完成订单（补单）
     */
    public function complete(CompleteOrderRequest $request, $id)
    {
        try {
            $order = $this->model->findOrFail($id);

            if ($order->status !== RechargeOrder::STATUS_PENDING && $order->status !== RechargeOrder::STATUS_PAID) {
                return $this->failed('订单状态不允许操作');
            }

            // 使用服务完成订单
            $this->rechargeService->completeOrder($order, [
                'admin_id' => $this->getLoginUserId(),
                'remark' => $request->remark,
            ]);

            // 记录操作日志
            OperationLog::record([
                'admin_id' => $this->getLoginUserId(),
                'module' => 'recharge',
                'action' => 'manual_complete',
                'related_type' => 'RechargeOrder',
                'related_id' => $order->id,
                'params' => $request->all(),
            ]);

            return $this->success($order->fresh(), '订单已完成');
        } catch (\Exception $e) {
            return $this->failed($e->getMessage());
        }
    }

    /**
     * 取消订单
     */
    public function cancel(CancelOrderRequest $request, $id)
    {
        $order = $this->model->findOrFail($id);

        if (!in_array($order->status, [RechargeOrder::STATUS_PENDING, RechargeOrder::STATUS_PAID])) {
            return $this->failed('订单状态不允许取消');
        }

        $order->status = RechargeOrder::STATUS_CANCELLED;
        $order->remark = $request->remark;
        $order->save();

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'recharge',
            'action' => 'cancel',
            'related_type' => 'RechargeOrder',
            'related_id' => $order->id,
            'params' => $request->all(),
        ]);

        return $this->success($order, '订单已取消');
    }

    /**
     * 订单统计
     */
    public function statistics(Request $request)
    {
        $query = $this->model->newQuery();

        // 时间筛选
        if ($request->has('date_range')) {
            $query->whereBetween('created_at', $request->date_range);
        }

        $stats = [
            'total_count' => $query->count(),
            'total_amount' => $query->sum('amount'),
            'completed_count' => (clone $query)->where('status', RechargeOrder::STATUS_COMPLETED)->count(),
            'completed_amount' => (clone $query)->where('status', RechargeOrder::STATUS_COMPLETED)->sum('actual_amount'),
            'pending_count' => (clone $query)->where('status', RechargeOrder::STATUS_PENDING)->count(),
            'pending_amount' => (clone $query)->where('status', RechargeOrder::STATUS_PENDING)->sum('amount'),
            // 这里必须 clone：selectRaw + groupBy 会叠加到同一个 $query 上，
            // 第二条实际会变成 GROUP BY status, channel_id，统计结果完全错误。
            'status_distribution' => (clone $query)->selectRaw('status, COUNT(*) as count, SUM(amount) as total_amount')
                ->groupBy('status')
                ->get(),
            'channel_distribution' => (clone $query)->selectRaw('channel_id, COUNT(*) as count, SUM(amount) as total_amount')
                ->groupBy('channel_id')
                ->with('channel:id,name')
                ->get(),
        ];

        return $this->success($stats);
    }

    /**
     * 导出订单
     */
    public function export(Request $request)
    {
        // TODO: 实现订单导出功能
        return $this->success(null, '导出功能开发中');
    }
}
