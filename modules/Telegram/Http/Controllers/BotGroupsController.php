<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\Request;
use Modules\Permissions\Support\DataScope;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\TelegramApiUsers;


class BotGroupsController extends Controller
{
    public function __construct(
        protected readonly BotGroups $model,
        protected readonly TelegramApiUsers $telegramApiUsersModel,
    ){}

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request): mixed
    {
        // 数据范围不需要在这里写：BotGroups 模型 use 了 DataRange trait，
        // CatchModel::getList() 会自动套上「本人 + 下级 + 角色数据权限 + 已授权模块」。
        return $this->model->setBeforeGetList(function ($query) use ($request) {
            if ($appId = $request->input('app_id')) {
                $query->where('app_id', $appId);
            }

            if ($botId = $request->input('bot_id')) {
                $query->where('bot_id', $botId);
            }

            $query->with(['groupGroup:*']);

            return $query;
        })->getList();
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
        // firstBy() 只按主键查，不受列表的数据范围约束，这里补一次越权校验
        $group = $this->model->firstBy($id);

        if ($group) {
            app(DataScope::class)->assertVisible($group);
        }

        return $group;
    }

    /**
     * @param int|string $id
     * @param Request $request
     * @return bool
     */
    public function update(int|string $id, Request $request)
    {
        $groupIds = $request->input('group_id');
        $groupGroupIds = $request->input('group_group_ids');

        if (empty($groupIds) || ! is_array($groupIds)) {
            return false;
        }

        // $groupIds 直接来自请求体，原来不校验归属，
        // 任意登录用户传任意 id 就能把别人的群划到自己的分组下。
        // 现在按统一的数据范围收敛：只能改自己可见范围内的群。
        $query = $this->model->whereIn('id', array_map('intval', $groupIds));

        app(DataScope::class)->apply($query, null, 'telegram', 'creator_id');

        $updated = $query->update(['group_id' => $groupGroupIds]);

        // 批量 update() 不触发模型事件，BotGroups::refreshCache() 不会被调用，
        // 这里补一次，否则 group_configs 缓存与库里的数据不一致。
        if ($updated > 0) {
            BotGroups::refreshCache();
        }

        return $updated;
    }

    /**
     * @param int|string $id
     * @return mixed
     */
    public function destroy(int|string $id): mixed
    {
        $group = $this->model->firstBy($id);

        if ($group) {
            app(DataScope::class)->assertVisible($group);
        }

        return $this->model->deleteBy($id);
    }
}
