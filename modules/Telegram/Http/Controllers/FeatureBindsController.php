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

    public function index(Request $request): mixed
    {
        return $this->featureBinds->setBeforeGetList(function ($query) use ($request) {
            if ($chatId = $request->input('chat_id')) {
                $query->where('chat_id', $chatId);
            }

            if ($botId = $request->input('bot_id')) {
                $query->where('bot_id', $botId);
            }

            $query->select(['id', 'feature_id', 'bot_id', 'chat_id', 'enabled', 'config'])
                  ->with(['feature:id,name,category,description']);

            return $query;
        })->getList();
    }

    public function store(Request $request): mixed
    {
        return $this->featureBinds->storeBy($request->all());
    }

    public function superStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chat_id' => 'nullable|integer',
            'bot_id' => 'nullable|integer',
            'feature_ids' => 'nullable|array',
            'feature_ids.*.feature_id' => 'required|integer',
            'feature_ids.*.enable' => 'required|integer',
        ]);

        $results = $this->telegramFeatureService->setBindFeature(
            featureBinds: $this->featureBinds,
            validated: $validated,
            loginUserId: $this->getLoginUserId()
        );

        if (empty($results)) {
            return response()->json([
                'success' => false,
                'message' => 'Something wrong happened',
                'data' => $results,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => sprintf(
                '成功新增 %d 条，更新 %d 条，删除 %d 条',
                count($results['created']),
                count($results['updated']),
                count($results['deleted'])
            ),
            'data' => $results,
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
