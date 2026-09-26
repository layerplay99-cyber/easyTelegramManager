<?php

namespace Modules\Telegram\Http\Controllers\Api;

use Carbon\Carbon;
use Catch\Base\CatchController;
use danog\MadelineProto\Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Telegram\Http\Requests\SCanLogRequest;
use Modules\Telegram\Models\Phones;
use Modules\Telegram\Models\Scanlogs;
use Modules\Telegram\Models\TelegramApiUsers;
use Modules\Telegram\Services\CollectServer;

class CollectPhoneApiController extends CatchController
{
    public function __construct(
        protected readonly Phones $phones,
        protected readonly SCanlogs $canLogs,
        protected readonly TelegramApiUsers $tUsers,
        protected readonly CollectServer $collectServer,
    ){}

    /**
     * 从指定ID开始获取数据，每次100条，用于提供数据给外部系统
     *
     * @param string $scantime
     * @return mixed
     */
    public function startIndex(string $scantime)
    {
        return $this->phones->setBeforeGetList(function ($query) use ($scantime) {
            if($scantime){
                $query = $query->where('scantime', '>=', Carbon::now()->subMinutes((int)$scantime)->timestamp);
            } else {
                $query = $query->where('scantime', null)->orderBy('id', 'asc');
            }
            $this->phones->setPerPage(10);
            return $query;
        })->getList();
    }

    public function pushScanLog(SCanLogRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $log = $this->canLogs->updateOrCreate(
                    ['tuser_id' => $request->input('tuser_id')],
                    $request->all()
                );

                $this->phones
                    ->where('phone', $request->input('phone'))
                    ->update(['scantime' => Carbon::now()->timestamp]);

                return $log;

            });
            return response()->json(['status' => 'success'] , 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'fail'] , 500);
        }
    }

    public function getTUser(string $id)
    {
        $query = $this->tUsers->newQuery();
        if($id) {
            $query = $query->where(['id' => $id]);
        }
        return $query->whereNotIn('login_status', [3])->get(); //过滤掉登录过期的账号
    }

    public function updateLoginStatus(Request $request)
    {
        $ids = $request->input('ids');
        //根据ids批量更新登录状态
        if(empty($ids)){
            return response()->json(['status' => 'fail', 'message' => 'ids不能为空'] , 400);
        }
        $updated = $this->tUsers->whereIn('id', $ids)->update(['login_status' => $request->input('login_status', 0)]);
        if($updated){
            return response()->json(['status' => 'success', 'message' => '更新成功'] , 200);
        } else {
            return response()->json(['status' => 'fail', 'message' => '更新失败'] , 500);
        }
    }

    /**
     * @throws Exception
     * @throws \Throwable
     */
    public function appLogin(Request $request)
    {
        $this->collectServer->appLogin($this->tUsers->find(1), $request->input('phone') );
        return response()->json(['status' => 'success'] , 200);
    }

    /**
     * @throws \Throwable
     * @throws Exception
     */
    public function completeLogin(Request $request)
    {
        $this->collectServer->completeLogin($this->tUsers->find(1), $request->input('code') );
        return response()->json(['status' => 'success'] , 200);
    }

    public function syncCollect(Request $request)
    {
        $this->collectServer->syncCollect($this->tUsers->find(1), $request->input('days', 3) );
        return response()->json(['status' => 'success'] , 200);
    }
}
