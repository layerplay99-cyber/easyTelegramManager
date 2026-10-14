<?php

namespace Modules\Telegram\Http\Controllers;

use Catch\CatchAdmin;
use Modules\Permissions\Support\DataScope;
use Modules\Telegram\Models\Member;
use Modules\Telegram\Models\Wallet;
use Modules\Telegram\Models\OperationLog;
use Modules\Telegram\Services\LedgerService;
use Modules\Telegram\Http\Requests\Wallet\WalletIndexRequest;
use Modules\Telegram\Http\Requests\Wallet\AdjustBalanceRequest;
use Modules\Telegram\Http\Requests\Wallet\CreateWalletRequest;
use Modules\Telegram\Http\Requests\Common\ToggleStatusRequest;
use Illuminate\Support\Facades\DB;

class WalletController extends BaseController
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
     *
     * 原来是裸 ->paginate()，绕过了 getList() 的 DataRange，
     * 部门管理员能看到所有部门的钱包。这里显式套上数据范围。
     */
    public function index(WalletIndexRequest $request)
    {
        $query = app(DataScope::class)->apply(
            $this->model->with('member'),
            null,
            'telegram',
            'creator_id'
        );

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

        // firstOrFail 只按主键查，不套数据范围，这里补越权校验
        app(DataScope::class)->assertVisible($wallet);

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

        app(DataScope::class)->assertVisible($wallet);

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
        // 只能给自己可见范围内的会员开户
        $member = Member::query()->find($request->member_id);

        if (! $member) {
            return $this->failed('会员不存在');
        }

        if (! app(DataScope::class)->isVisible($member)) {
            return $this->failed('无权访问该会员');
        }

        // 检查是否已存在
        $exists = $this->model->where('member_id', $request->member_id)
            ->where('currency', $request->currency)
            ->exists();

        if ($exists) {
            return $this->failed('该币种钱包已存在');
        }

        // creator_id 不在 Wallet 的 fillable 里（防批量赋值改余额那套约束），
        // 而 create() 不走 filterData() 的自动填充，不显式写就是 0；
        // creator_id = 0 即「无主数据」，会对所有后台用户可见。
        // 这里继承会员的归属人，让钱包跟着会员（也就是跟着机器人/部门）走。
        $wallet = $this->model->create([
            'member_id' => $request->member_id,
            'currency' => $request->currency,
            'balance' => 0,
            'frozen_balance' => 0,
            'total_recharge' => 0,
            'total_withdraw' => 0,
            'status' => 1,
            'creator_id' => (int) ($member->creator_id ?: $this->getLoginUserId()),
        ]);

        return $this->success($wallet, '钱包创建成功');
    }

    /**
     * 钱包统计
     */
    public function statistics(\Illuminate\Http\Request $request)
    {
        // 统计同样要按数据范围收窄，否则一个 count() 就把全平台规模漏出去了
        $query = app(DataScope::class)->apply(
            $this->model->newQuery(),
            null,
            'telegram',
            'creator_id'
        );

        $stats = [
            'total_wallets' => $query->count(),
            'active_wallets' => (clone $query)->where('status', 1)->count(),
            'total_balance' => (clone $query)->sum('balance'),
            'total_frozen' => (clone $query)->sum('frozen_balance'),
            'currency_stats' => (clone $query)->selectRaw('currency, COUNT(*) as count, SUM(balance) as total_balance')
                ->groupBy('currency')
                ->get(),
        ];

        return $this->success($stats);
    }
}
