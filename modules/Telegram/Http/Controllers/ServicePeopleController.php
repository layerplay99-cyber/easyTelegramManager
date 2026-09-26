<?php

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController;
use Illuminate\Http\Request;
use Modules\Telegram\Models\ServicePeoples;
use Modules\Telegram\Models\TelegramApiUsers;

class ServicePeopleController extends CatchController
{
    public function __construct(
        protected readonly ServicePeoples $servicePeoples,
        protected readonly TelegramApiUsers $telegramApiUsers,
    )
    {

    }

    public function index(Request $request)
    {
        return $this->servicePeoples->setBeforeGetList(function ($query) use ($request) {
            if ($request->has('app_id')) {
                $query->where('app_id', $request->input('app_id'));
            }

            // 原来是 with(['groups'])，但 ServicePeoples 模型只定义了 botGroup()，
            // 没有 groups 关系 → 每次请求都抛 "Call to undefined relationship [groups]" (500)
            $query->with(['botGroup']);
            return $query;
        })->getList();
    }

    public function store(Request $request)
    {
        return $this->servicePeoples->storeBy($request->all());
    }

    public function show($id)
    {
        return $this->servicePeoples->firstBy($id);
    }


    public function update($id, Request $request)
    {
        return $this->servicePeoples->updateBy($id, $request->all());
    }

    public function destroy($id)
    {
        return $this->servicePeoples->deleteBy($id);
    }
}
