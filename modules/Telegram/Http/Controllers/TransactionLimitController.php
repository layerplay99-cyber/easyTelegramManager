<?php

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController;
use Modules\Telegram\Models\TransactionLimit;
use Modules\Telegram\Models\OperationLog;
use Modules\Telegram\Http\Requests\TransactionLimit\CreateTransactionLimitRequest;
use Modules\Telegram\Http\Requests\TransactionLimit\UpdateTransactionLimitRequest;
use Modules\Telegram\Http\Requests\Common\ToggleStatusRequest;
use Illuminate\Http\Request;

class TransactionLimitController extends CatchController
{
    protected $model;

    public function __construct(TransactionLimit $transactionLimit)
    {
        $this->model = $transactionLimit;
    }

    /**
     * 交易限额列表
     */
    public function index(Request $request)
    {
        $query = $this->model->newQuery();

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('level')) {
            $query->where('level', $request->level);
        }

        if ($request->has('currency')) {
            $query->where('currency', $request->currency);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return $this->success($query->orderByDesc('id')->paginate($request->get('limit', 20)));
    }

    /**
     * 创建交易限额
     */
    public function store(CreateTransactionLimitRequest $request)
    {
        $limit = $this->model->create($request->validated());

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'transaction_limit',
            'action' => 'create',
            'related_type' => 'TransactionLimit',
            'related_id' => $limit->id,
            'params' => $request->all(),
        ]);

        return $this->success($limit, '交易限额创建成功');
    }

    /**
     * 更新交易限额
     */
    public function update(UpdateTransactionLimitRequest $request, $id)
    {
        $limit = $this->model->findOrFail($id);
        $limit->update($request->validated());

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'transaction_limit',
            'action' => 'update',
            'related_type' => 'TransactionLimit',
            'related_id' => $limit->id,
            'params' => $request->all(),
        ]);

        return $this->success($limit, '交易限额更新成功');
    }

    /**
     * 删除交易限额
     */
    public function destroy($id)
    {
        $limit = $this->model->findOrFail($id);
        $limit->delete();

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'transaction_limit',
            'action' => 'delete',
            'related_type' => 'TransactionLimit',
            'related_id' => $id,
        ]);

        return $this->success(null, '交易限额删除成功');
    }

    /**
     * 切换状态
     */
    public function toggleStatus(ToggleStatusRequest $request, $id)
    {
        $limit = $this->model->findOrFail($id);
        $limit->status = $request->status;
        $limit->save();

        return $this->success($limit, '状态已' . $request->getStatusText());
    }

    /**
     * 获取限额（用于前端查询）
     */
    public function getLimit(Request $request)
    {
        $request->validate([
            'type' => 'required|in:recharge,withdraw',
            'level' => 'nullable|string',
            'currency' => 'nullable|string',
        ]);

        $limit = TransactionLimit::getLimitConfig(
            $request->type,
            $request->get('level', 'default'),
            $request->get('currency', 'USDT')
        );

        if (!$limit) {
            return $this->failed('限额配置不存在');
        }

        return $this->success($limit);
    }
}
