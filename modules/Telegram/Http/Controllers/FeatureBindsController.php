<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Telegram\Models\FeaturesBinds;
use Modules\Telegram\Services\Feature\TelegramFeatureService;

class FeatureBindsController extends CatchController
{
    public function __construct(
        protected readonly FeaturesBinds $featureBinds,
        protected readonly TelegramFeatureService $telegramFeatureService,
    ) {}

    /**
     * 绑定列表
     *
     * 两级：
     *   ?bot_id=3               → 机器人级白名单（chat_id = 0）
     *   ?bot_id=3&chat_id=-100… → 该群自己勾了哪些
     *
     * chat_id = 0 表示「不针对具体群」，等价于以前的 NULL。
     * 不能再写 whereNull：库里已经不允许 NULL 了，而且 NULL 会让唯一索引失效。
     */
    public function index(Request $request): mixed
    {
        return $this->featureBinds->setBeforeGetList(function ($query) use ($request) {
            $chatId = $request->input('chat_id');
            $botId = $request->input('bot_id');

            if ($botId !== null && $botId !== '') {
                $query->where('bot_id', (int) $botId);

                // 群 ID 没传（或显式传 0）就是查机器人级白名单
                $query->where('chat_id', (int) ($chatId ?: FeaturesBinds::ENTITY_WIDE_CHAT_ID));
            } elseif ($chatId !== null && $chatId !== '') {
                $query->where('chat_id', (int) $chatId);
            }

            $query->select(['id', 'feature_id', 'bot_id', 'chat_id', 'enabled', 'config', 'third_config_id'])
                  ->with(['feature:id,name,category,description']);

            return $query;
        })->getList();
    }

    public function store(Request $request): mixed
    {
        return $this->featureBinds->storeBy($request->all());
    }

    /**
     * 整存整取：前端把「这个实体最终该有哪几个功能」整份传过来
     *
     * 群级必须带 bot_id —— 没有机器人归属就无从判断「这个群能用哪些」，
     * 历史数据里 bot_id 为空的群级绑定在执行期根本匹配不到（执行期按
     * bot_id = 当前机器人查），配了也不生效。
     */
    public function superStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chat_id' => 'nullable|integer',
            'bot_id' => 'nullable|integer',
            'feature_ids' => 'nullable|array',
            'feature_ids.*.feature_id' => 'required|integer',
            // 开关值前后端都可能是 1/0 或 true/false，一律按布尔收，存成 0/1
            'feature_ids.*.enable' => 'nullable|boolean',
            'feature_ids.*.enabled' => 'nullable|boolean',
            'feature_ids.*.third_config_id' => 'nullable|integer',
        ]);

        $chatId = (int) ($validated['chat_id'] ?? 0);

        if ($chatId !== 0 && empty($validated['bot_id'])) {
            return response()->json([
                'success' => false,
                'message' => '群级功能配置必须指定所属机器人（bot_id）',
            ], 422);
        }

        $results = $this->telegramFeatureService->setBindFeature(
            featureBinds: $this->featureBinds,
            validated: $validated,
            loginUserId: $this->getLoginUserId()
        );

        // 三个统计键由 setBindFeature 保证存在；这里再兜一层，
        // 避免以后谁改了返回值又把「Undefined array key」送回前端。
        $created = $results['created'] ?? [];
        $updated = $results['updated'] ?? [];
        $deleted = $results['deleted'] ?? [];

        if (($results['success'] ?? false) !== true) {
            return response()->json([
                'success' => false,
                'message' => $results['message'] ?? 'Something wrong happened',
                'created' => $created,
                'updated' => $updated,
                'deleted' => $deleted,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => sprintf(
                '成功新增 %d 条，更新 %d 条，删除 %d 条',
                count($created),
                count($updated),
                count($deleted)
            ),
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
        ]);
    }

    public function show(int|string $id): mixed
    {
        return $this->featureBinds->firstBy($id);
    }

    public function update(int|string $id, Request $request): mixed
    {
        return $this->featureBinds->updateBy($id, $request->all());
    }

    public function destroy(int|string $id): mixed
    {
        return $this->featureBinds->deleteBy($id);
    }
}
