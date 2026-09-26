<?php

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController;
use Modules\Telegram\Models\ExchangeRate;
use Modules\Telegram\Models\OperationLog;
use Modules\Telegram\Http\Requests\ExchangeRate\CreateExchangeRateRequest;
use Modules\Telegram\Http\Requests\ExchangeRate\UpdateExchangeRateRequest;
use Modules\Telegram\Http\Requests\ExchangeRate\GetRateRequest;
use Modules\Telegram\Http\Requests\ExchangeRate\ConvertCurrencyRequest;
use Illuminate\Http\Request;

class ExchangeRateController extends CatchController
{
    protected $model;

    public function __construct(ExchangeRate $exchangeRate)
    {
        $this->model = $exchangeRate;
    }

    /**
     * 汇率列表
     */
    public function index(Request $request)
    {
        $query = $this->model->newQuery();

        if ($request->has('from_currency')) {
            $query->where('from_currency', $request->from_currency);
        }

        if ($request->has('to_currency')) {
            $query->where('to_currency', $request->to_currency);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return $this->success($query->orderByDesc('id')->paginate($request->get('limit', 20)));
    }

    /**
     * 创建汇率
     */
    public function store(CreateExchangeRateRequest $request)
    {
        // 检查是否已存在
        $exists = $this->model
            ->where('from_currency', $request->from_currency)
            ->where('to_currency', $request->to_currency)
            ->exists();

        if ($exists) {
            return $this->failed('该汇率配置已存在');
        }

        $formattedRates = $request->getFormattedRates();
        $data = array_merge($request->except(['rate', 'buy_rate', 'sell_rate']), $formattedRates);

        $rate = $this->model->create($data);

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'exchange_rate',
            'action' => 'create',
            'related_type' => 'ExchangeRate',
            'related_id' => $rate->id,
            'params' => $request->all(),
        ]);

        return $this->success($rate, '汇率创建成功');
    }

    /**
     * 更新汇率
     */
    public function update(UpdateExchangeRateRequest $request, $id)
    {
        $rate = $this->model->findOrFail($id);
        $rate->update($request->validated());

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'exchange_rate',
            'action' => 'update',
            'related_type' => 'ExchangeRate',
            'related_id' => $rate->id,
            'params' => $request->all(),
        ]);

        return $this->success($rate, '汇率更新成功');
    }

    /**
     * 删除汇率
     */
    public function destroy($id)
    {
        $rate = $this->model->findOrFail($id);
        $rate->delete();

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'exchange_rate',
            'action' => 'delete',
            'related_type' => 'ExchangeRate',
            'related_id' => $id,
        ]);

        return $this->success(null, '汇率删除成功');
    }

    /**
     * 获取汇率（用于计算）
     */
    public function getRate(GetRateRequest $request)
    {
        $rate = ExchangeRate::getRate(
            $request->from_currency,
            $request->to_currency,
            $request->type
        );

        if ($rate === null) {
            return $this->failed('汇率不存在');
        }

        return $this->success(['rate' => $rate]);
    }

    /**
     * 货币转换
     */
    public function convert(ConvertCurrencyRequest $request)
    {
        $result = ExchangeRate::convert(
            $request->amount,
            $request->from_currency,
            $request->to_currency,
            $request->type
        );

        if ($result === null) {
            return $this->failed('汇率不存在');
        }

        return $this->success([
            'amount' => $request->amount,
            'from_currency' => $request->from_currency,
            'to_currency' => $request->to_currency,
            'result' => $result,
        ]);
    }
}
