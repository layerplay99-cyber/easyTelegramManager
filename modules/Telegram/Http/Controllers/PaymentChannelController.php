<?php

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController;
use Modules\Telegram\Models\PaymentChannel;
use Modules\Telegram\Models\OperationLog;
use Modules\Telegram\Http\Requests\PaymentChannel\PaymentChannelIndexRequest;
use Modules\Telegram\Http\Requests\PaymentChannel\CreatePaymentChannelRequest;
use Modules\Telegram\Http\Requests\PaymentChannel\UpdatePaymentChannelRequest;
use Modules\Telegram\Http\Requests\Common\ToggleStatusRequest;
use Illuminate\Http\Request;

class PaymentChannelController extends CatchController
{
    protected $model;

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->model = $paymentChannel;
    }

    /**
     * 支付通道列表
     */
    public function index(PaymentChannelIndexRequest $request)
    {
        $query = $this->model->newQuery();

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->has('currency')) {
            $query->where('currency', $request->input('currency'));
        }

        if ($request->has('method')) {
            $query->where('method', $request->input('method'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->success($query->orderBy('priority', 'desc')->paginate($request->get('limit', 20)));
    }

    /**
     * 创建支付通道
     */
    public function store(CreatePaymentChannelRequest $request)
    {
        $channel = $this->model->create($request->validated());

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'payment_channel',
            'action' => 'create',
            'related_type' => 'PaymentChannel',
            'related_id' => $channel->id,
            'params' => $request->except(['secret_key']),
        ]);

        return $this->success($channel, '支付通道创建成功');
    }

    /**
     * 更新支付通道
     */
    public function update(UpdatePaymentChannelRequest $request, $id)
    {
        $channel = $this->model->findOrFail($id);
        $channel->update($request->validated());

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'payment_channel',
            'action' => 'update',
            'related_type' => 'PaymentChannel',
            'related_id' => $channel->id,
            'params' => $request->except(['secret_key']),
        ]);

        return $this->success($channel, '支付通道更新成功');
    }

    /**
     * 删除支付通道
     */
    public function destroy($id)
    {
        $channel = $this->model->findOrFail($id);
        $channel->delete();

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'payment_channel',
            'action' => 'delete',
            'related_type' => 'PaymentChannel',
            'related_id' => $id,
        ]);

        return $this->success(null, '支付通道删除成功');
    }

    /**
     * 切换状态
     */
    public function toggleStatus(ToggleStatusRequest $request, $id)
    {
        $channel = $this->model->findOrFail($id);
        $channel->status = $request->status;
        $channel->save();

        // 记录操作日志
        OperationLog::record([
            'admin_id' => $this->getLoginUserId(),
            'module' => 'payment_channel',
            'action' => 'toggle_status',
            'related_type' => 'PaymentChannel',
            'related_id' => $channel->id,
            'params' => $request->all(),
        ]);

        return $this->success($channel, '状态更新成功');
    }

    /**
     * 获取可用通道列表（用于前端选择）
     */
    public function available(Request $request)
    {
        $type = $request->get('type', 'recharge');
        $currency = $request->get('currency', 'USDT');

        $channels = $this->model
            ->where('status', PaymentChannel::STATUS_ENABLED)
            ->where('currency', $currency)
            ->where(function ($query) use ($type) {
                $query->where('type', $type)
                    ->orWhere('type', PaymentChannel::TYPE_BOTH);
            })
            ->orderBy('priority', 'desc')
            ->get();

        return $this->success($channels);
    }
}
