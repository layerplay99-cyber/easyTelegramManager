<?php

namespace Modules\Telegram\Http\Controllers;

use Catch\CatchAdmin;
use Catch\Base\CatchController;
use Modules\Telegram\Models\WithdrawOrder;
use Modules\Telegram\Models\OperationLog;
use Modules\Telegram\Services\WithdrawService;
use Modules\Telegram\Http\Requests\Order\WithdrawOrderIndexRequest;
use Modules\Telegram\Http\Requests\Order\CompleteOrderRequest;
use Modules\Telegram\Http\Requests\Order\CancelOrderRequest;
use Modules\Telegram\Http\Requests\Order\BatchProcessRequest;
use Illuminate\Http\Request;

class WithdrawOrderController extends CatchController
{
    protected $model;
    protected $withdrawService;

    public function __construct(WithdrawOrder $withdrawOrder, WithdrawService $withdrawService)
    {
        $this->model = $withdrawOrder;
        $this->withdrawService = $withdrawService;
    }

    /**
     * 提现订单列表
     */
    public function index(WithdrawOrderIndexRequest $request)
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
     * 手动完成订单
     */
    public function complete(CompleteOrderRequest $request, $id)
    {
        try {
            $order = $this->model->findOrFail($id);

            if (!in_array($order->status, [WithdrawOrder::STATUS_PENDING, WithdrawOrder::STATUS_PROCESSING])) {
                return $this->failed('订单状态不允许操作');
            }

            // 使用服务完成订单
            $this->withdrawService->completeOrder($order, [
                'admin_id' => $this->getLoginUserId(),
                'remark' => $request->remark,
            ]);

            // 记录操作日志
            OperationLog::record([
                'admin_id' => $this->getLoginUserId(),
                'module' => 'withdraw',
                'action' => 'manual_complete',
                'related_type' => 'WithdrawOrder',
                'related_id' => $order->id,
                'params' => $request->all(),
            ]);

            return $this->success($order->fresh(), '订单已完成');
        } catch (\Exception $e) {
            return $this->failed($e->getMessage());
        }
    }

    /**
     * 拒绝/取消订单
     */
    public function cancel(CancelOrderRequest $request, $id)
    {
        try {
            $order = $this->model->findOrFail($id);

            if (!$order->canCancel()) {
                return $this->failed('订单状态不允许取消');
            }

            // 使用服务取消订单（会解冻余额）
            $this->withdrawService->cancelOrder($order, $request->remark);

            // 记录操作日志
            OperationLog::record([
                'admin_id' => $this->getLoginUserId(),
                'module' => 'withdraw',
                'action' => 'cancel',
                'related_type' => 'WithdrawOrder',
                'related_id' => $order->id,
                'params' => $request->all(),
            ]);

            return $this->success($order->fresh(), '订单已取消');
        } catch (\Exception $e) {
            return $this->failed($e->getMessage());
        }
    }

    /**
     * 标记为处理中
     */
    public function processing(Request $request, $id)
    {
        $order = $this->model->findOrFail($id);

        if ($order->status !== WithdrawOrder::STATUS_PENDING) {
            return $this->failed('订单状态不允许操作');
        }

        $order->status = WithdrawOrder::STATUS_PROCESSING;
        $order->processed_at = now();
        $order->save();

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'withdraw',
            'action' => 'processing',
            'related_type' => 'WithdrawOrder',
            'related_id' => $order->id,
        ]);

        return $this->success($order, '订单已标记为处理中');
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
            'completed_count' => (clone $query)->where('status', WithdrawOrder::STATUS_COMPLETED)->count(),
            'completed_amount' => (clone $query)->where('status', WithdrawOrder::STATUS_COMPLETED)->sum('actual_amount'),
            'pending_count' => (clone $query)->where('status', WithdrawOrder::STATUS_PENDING)->count(),
            'pending_amount' => (clone $query)->where('status', WithdrawOrder::STATUS_PENDING)->sum('amount'),
            'processing_count' => (clone $query)->where('status', WithdrawOrder::STATUS_PROCESSING)->count(),
            'processing_amount' => (clone $query)->where('status', WithdrawOrder::STATUS_PROCESSING)->sum('amount'),
            'status_distribution' => $query->selectRaw('status, COUNT(*) as count, SUM(amount) as total_amount')
                ->groupBy('status')
                ->get(),
        ];

        return $this->success($stats);
    }

    /**
     * 批量处理
     */
    public function batchProcess(BatchProcessRequest $request)
    {
        $successCount = 0;
        $failCount = 0;
        $errors = [];

        foreach ($request->ids as $id) {
            try {
                $order = $this->model->findOrFail($id);

                switch ($request->action) {
                    case 'complete':
                        $this->withdrawService->completeOrder($order, [
                            'admin_id' => $this->getLoginUserId(),
                            'remark' => $request->remark,
                        ]);
                        break;
                    case 'cancel':
                        $this->withdrawService->cancelOrder($order, $request->remark ?? '批量取消');
                        break;
                    case 'processing':
                        $order->status = WithdrawOrder::STATUS_PROCESSING;
                        $order->processed_at = now();
                        $order->save();
                        break;
                }

                $successCount++;
            } catch (\Exception $e) {
                $failCount++;
                $errors[] = "订单 {$id}: {$e->getMessage()}";
            }
        }

        return $this->success([
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'errors' => $errors,
        ], "批量{$request->getActionText()}完成，成功 {$successCount} 个");
    }
}

