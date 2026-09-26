<?php

namespace Modules\Telegram\Http\Controllers;

use Catch\CatchAdmin;
use Catch\Base\CatchController;
use Modules\Telegram\Models\Wallet;
use Modules\Telegram\Models\OperationLog;
use Modules\Telegram\Services\LedgerService;
use Modules\Telegram\Http\Requests\Wallet\WalletIndexRequest;
use Modules\Telegram\Http\Requests\Wallet\AdjustBalanceRequest;
use Modules\Telegram\Http\Requests\Wallet\CreateWalletRequest;
use Modules\Telegram\Http\Requests\Common\ToggleStatusRequest;
use Illuminate\Support\Facades\DB;

class WalletController extends CatchController
{
    protected $model;
    protected $ledgerService;

    public function __construct(Wallet $wallet, LedgerService $ledgerService)
    {
        $this->model = $wallet;
        $this->ledgerService = $ledgerService;
    }

    /**
     * 钱包列表
     */
    public function index(WalletIndexRequest $request)
    {
        $query = $this->model->with('member');

        if ($request->has('member_id')) {
            $query->where('member_id', $request->member_id);
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
     * 手动调整余额
     */
    public function adjustBalance(AdjustBalanceRequest $request, $id)
    {
        $wallet = $this->model->findOrFail($id);
        $amount = $request->getFormattedAmount();

        try {
            DB::beginTransaction();

            if ($request->isReward()) {
                // 奖励加钱
                $wallet->addBalance($amount, 'reward', null, [
                    'title' => $request->title,
                    'description' => $request->description,
                    'operator_id' => $this->getLoginUserId(),
                    'operator_type' => 'admin',
                    'ip' => $request->ip(),
                ]);
            } else {
                // 扣款
                $wallet->reduceBalance($amount, 'deduct', null, [
                    'title' => $request->title,
                    'description' => $request->description,
                    'operator_id' => $this->getLoginUserId(),
                    'operator_type' => 'admin',
                    'ip' => $request->ip(),
                ]);
            }

            // 记录操作日志
            OperationLog::record([
                'admin_id' => $this->getLoginUserId(),
                'module' => 'wallet',
                'action' => 'adjust_balance',
                'related_type' => 'Wallet',
                'related_id' => $wallet->id,
                'params' => $request->all(),
            ]);

            DB::commit();

            return $this->success($wallet->fresh(), '余额调整成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->failed($e->getMessage());
        }
    }

    /**
     * 冻结/解冻钱包
     */
    public function toggleStatus(ToggleStatusRequest $request, $id)
    {
        $wallet = $this->model->findOrFail($id);
        $wallet->status = $request->status;
        $wallet->save();

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'wallet',
            'action' => 'toggle_status',
            'related_type' => 'Wallet',
            'related_id' => $wallet->id,
            'params' => $request->all(),
        ]);

        return $this->success($wallet, '钱包' . $request->getStatusText());
    }

    /**
     * 创建钱包
     */
    public function store(CreateWalletRequest $request)
    {
        // 检查是否已存在
        $exists = $this->model->where('member_id', $request->member_id)
            ->where('currency', $request->currency)
            ->exists();

        if ($exists) {
            return $this->failed('该币种钱包已存在');
        }

        $wallet = $this->model->create([
            'member_id' => $request->member_id,
            'currency' => $request->currency,
            'balance' => 0,
            'frozen_balance' => 0,
            'total_recharge' => 0,
            'total_withdraw' => 0,
            'status' => 1,
        ]);

        return $this->success($wallet, '钱包创建成功');
    }

    /**
     * 钱包统计
     */
    public function statistics(\Illuminate\Http\Request $request)
    {
        $stats = [
            'total_wallets' => $this->model->count(),
            'active_wallets' => $this->model->where('status', 1)->count(),
            'total_balance' => $this->model->sum('balance'),
            'total_frozen' => $this->model->sum('frozen_balance'),
            'currency_stats' => $this->model->selectRaw('currency, COUNT(*) as count, SUM(balance) as total_balance')
                ->groupBy('currency')
                ->get(),
        ];

        return $this->success($stats);
    }
}
