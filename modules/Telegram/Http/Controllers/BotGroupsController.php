<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\Request;
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
        $user = $this->getLoginUser();
        return $this->model->setBeforeGetList(function ($query) use ($user, $request) {
            if ($appId = $request->input('app_id')) {
                $query->where('app_id', $appId);
            }

            if ($botId = $request->input('bot_id')) {
                $query->where('bot_id', $botId);
            }

            if (! $user->isSuperAdmin()) {
                $query->where('creator_id', $this->getLoginUserId());
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
        return $this->model->firstBy($id);
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
        $query = $this->model->whereIn('id', array_map('intval', $groupIds));

        $user = $this->getLoginUser();
        if (! $user->isSuperAdmin()) {
            $query->where('creator_id', $this->getLoginUserId());
        }

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
        return $this->model->deleteBy($id);
    }
}
