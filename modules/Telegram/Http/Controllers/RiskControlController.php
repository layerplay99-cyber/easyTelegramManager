<?php

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController;
use Modules\Telegram\Models\RiskControlRule;
use Modules\Telegram\Models\RiskControlLog;
use Modules\Telegram\Models\OperationLog;
use Modules\Telegram\Http\Requests\RiskControl\CreateRiskRuleRequest;
use Modules\Telegram\Http\Requests\RiskControl\UpdateRiskRuleRequest;
use Modules\Telegram\Http\Requests\RiskControl\RiskLogIndexRequest;
use Modules\Telegram\Http\Requests\RiskControl\HandleRiskLogRequest;
use Modules\Telegram\Http\Requests\Common\ToggleStatusRequest;
use Illuminate\Http\Request;

class RiskControlController extends CatchController
{
    protected $ruleModel;
    protected $logModel;

    public function __construct(RiskControlRule $ruleModel, RiskControlLog $logModel)
    {
        $this->ruleModel = $ruleModel;
        $this->logModel = $logModel;
    }

    /**
     * 风控规则列表
     */
    public function rules(Request $request)
    {
        $query = $this->ruleModel->newQuery();

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('risk_level')) {
            $query->where('risk_level', $request->risk_level);
        }

        return $this->success($query->orderBy('priority', 'desc')->paginate($request->get('limit', 20)));
    }

    /**
     * 创建风控规则
     */
    public function createRule(CreateRiskRuleRequest $request)
    {
        $rule = $this->ruleModel->create($request->validated());

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'risk_control',
            'action' => 'create_rule',
            'related_type' => 'RiskControlRule',
            'related_id' => $rule->id,
            'params' => $request->all(),
        ]);

        return $this->success($rule, '风控规则创建成功：' . $request->getRiskLevelText() . '风险 - ' . $request->getActionText());
    }

    /**
     * 更新风控规则
     */
    public function updateRule(UpdateRiskRuleRequest $request, $id)
    {
        $rule = $this->ruleModel->findOrFail($id);
        $rule->update($request->validated());

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'risk_control',
            'action' => 'update_rule',
            'related_type' => 'RiskControlRule',
            'related_id' => $rule->id,
            'params' => $request->all(),
        ]);

        return $this->success($rule, '风控规则更新成功');
    }

    /**
     * 删除风控规则
     */
    public function deleteRule($id)
    {
        $rule = $this->ruleModel->findOrFail($id);
        $rule->delete();

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'risk_control',
            'action' => 'delete_rule',
            'related_type' => 'RiskControlRule',
            'related_id' => $id,
        ]);

        return $this->success(null, '风控规则删除成功');
    }

    /**
     * 切换规则状态
     */
    public function toggleRuleStatus(ToggleStatusRequest $request, $id)
    {
        $rule = $this->ruleModel->findOrFail($id);
        $rule->status = $request->status;
        $rule->save();

        return $this->success($rule, '规则状态已' . $request->getStatusText());
    }

    /**
     * 风控日志列表
     */
    public function logs(RiskLogIndexRequest $request)
    {
        $query = $this->logModel->with(['member', 'rule']);

        if ($request->has('member_id')) {
            $query->where('member_id', $request->member_id);
        }

        if ($request->has('rule_id')) {
            $query->where('rule_id', $request->rule_id);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('risk_level')) {
            $query->where('risk_level', $request->risk_level);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('created_at')) {
            $query->whereBetween('created_at', $request->created_at);
        }

        return $this->success($query->orderByDesc('id')->paginate($request->get('limit', 20)));
    }

    /**
     * 处理风控日志
     */
    public function handleLog(HandleRiskLogRequest $request, $id)
    {
        $log = $this->logModel->findOrFail($id);

        if ($log->status !== RiskControlLog::STATUS_PENDING) {
            return $this->failed('该日志已处理');
        }

        $adminId = $this->getLoginUserId();

        if ($request->isHandled()) {
            $log->markAsHandled($adminId, $request->remark);
        } else {
            $log->markAsIgnored($adminId, $request->remark);
        }

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $adminId,
            'module' => 'risk_control',
            'action' => 'handle_log',
            'related_type' => 'RiskControlLog',
            'related_id' => $log->id,
            'params' => $request->all(),
        ]);

        return $this->success($log, '风控日志处理成功');
    }

    /**
     * 风控统计
     */
    public function statistics(Request $request)
    {
        $query = $this->logModel->newQuery();

        if ($request->has('date_range')) {
            $query->whereBetween('created_at', $request->date_range);
        }

        $stats = [
            'total_count' => $query->count(),
            'pending_count' => (clone $query)->where('status', RiskControlLog::STATUS_PENDING)->count(),
            'handled_count' => (clone $query)->where('status', RiskControlLog::STATUS_HANDLED)->count(),
            'ignored_count' => (clone $query)->where('status', RiskControlLog::STATUS_IGNORED)->count(),
            'risk_level_distribution' => $query->selectRaw('risk_level, COUNT(*) as count')
                ->groupBy('risk_level')
                ->get(),
            'type_distribution' => $query->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->get(),
            'action_distribution' => $query->selectRaw('action, COUNT(*) as count')
                ->groupBy('action')
                ->get(),
        ];

        return $this->success($stats);
    }
}
