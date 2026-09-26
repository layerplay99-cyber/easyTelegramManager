<?php

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController;
use Illuminate\Http\Request;
use Modules\Telegram\Models\GroupConfigs;

class GroupConfigController extends CatchController
{
    public function __construct(
        protected readonly GroupConfigs $groupConfigs,
    )
    {

    }

    public function index(Request $request)
    {
        return $this->groupConfigs->getList();
    }

    public function store(Request $request)
    {
        return $this->groupConfigs->storeBy($request->all());
    }

    public function show($id)
    {
        return $this->groupConfigs->firstBy($id);
    }

    public function update($id, Request $request)
    {
        return $this->groupConfigs->updateBy($id, $request->all());
    }

    public function destroy($id)
    {
        return $this->groupConfigs->deleteBy($id);
    }
}
