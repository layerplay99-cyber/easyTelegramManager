<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\GroupGroups;


class BotGroupGroupController extends Controller
{
    public function __construct(
        protected readonly GroupGroups $model,
        protected readonly BotGroups $botGroupsModel,
    ){}

    /**
     * @return mixed
     */
    public function index(): mixed
    {
        // 数据范围由 GroupGroups 模型的 DataRange trait 自动生效
        return $this->model->getList();
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request): mixed
    {
        return $this->model->storeBy($request->all());
    }

    /**
     * @param int|string $id
     * @return mixed
     */
    public function show(int|string $id): mixed
    {
        return $this->model->firstBy($id);
    }

    /**
     * @param int|string $id
     * @param Request $request
     * @return mixed
     */
    public function update(int|string $id, Request $request): mixed
    {
        return $this->model->updateBy($id, $request->all());
    }

    /**
     * @param int|string $id
     * @return JsonResponse
     * @throws \Throwable
     */
    public function destroy(int|string $id): JsonResponse
    {
        try {
            DB::transaction(function () use ($id) {
                // 将所有关联的 BotGroups 的 group_id 置为 0
                $this->botGroupsModel
                    ->where('group_id', $id)
                    ->update(['group_id' => 0]);

                // 删除分组
                $this->model->deleteBy($id);
            });

            return response()->json([
                'status' => 'success',
                'message' => '分组删除成功，关联群组已解绑'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => '删除失败：' . $e->getMessage()
            ], 500);
        }
    }
}
