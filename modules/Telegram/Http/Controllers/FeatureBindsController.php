<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use App\Support\ServiceExpiry;
use Catch\Base\CatchController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Permissions\Support\MerchantContext;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Models\FeaturesBinds;
use Modules\Telegram\Models\GroupConfigs;
use Modules\Telegram\Services\Feature\TelegramFeatureService;
use Modules\User\Models\User;

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
        $data = $request->all();

        // 单条绑定走同一套商户归属校验（与 superStore 口径一致）
        $merchantError = $this->merchantBindError(
            [["feature_id" => $data["feature_id"] ?? 0]],
            (int) ($data["bot_id"] ?? 0),
            (int) ($data["chat_id"] ?? 0)
        );

        if ($merchantError !== null) {
            throw new \Catch\Exceptions\FailedException($merchantError);
        }

        return $this->featureBinds->storeBy($data);
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

        // 机器人维度：配了多上游候选池后，每个需上游功能必须明确指定一个
        // 「属于该机器人 ∩ 该上游支持此功能」的上游；官方 API 功能不许带上游。
        // 群维度继承机器人的绑定，不在这里重复校验（前端只读展示上游名）。
        // 候选池还没维护的历史机器人跳过，避免一保存就把老配置全拦死。
        $botId = (int) ($validated['bot_id'] ?? 0);
        $resolver = app(\Modules\Telegram\Services\Feature\UpstreamResolver::class);

        if ($chatId === 0 && $botId > 0 && $resolver->candidateIds($botId) !== []) {
            foreach ($validated['feature_ids'] ?? [] as $item) {
                try {
                    $resolver->assertAssignable(
                        $botId,
                        (int) $item['feature_id'],
                        isset($item['third_config_id']) ? (int) $item['third_config_id'] : null
                    );
                } catch (\InvalidArgumentException $e) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage(),
                    ], 422);
                }
            }
        }

        // 商户归属校验：商户定制功能只能绑到绑定了同一商户号的群；
        // 机器人级绑定一律拒绝（商户功能按群商户号路由，机器人级配了也不生效）
        $merchantError = $this->merchantBindError(
            $validated['feature_ids'] ?? [],
            $botId,
            $chatId
        );

        if ($merchantError !== null) {
            return response()->json([
                'success' => false,
                'message' => $merchantError,
            ], 422);
        }

        // 付费功能：归属账号没有有效套餐时提前拒绝，避免「绑了也用不了」。
        // 平台超管不受限（代客开通/验证场景）。
        $paidError = $this->paidBindError($validated['feature_ids'] ?? [], $botId);

        if ($paidError !== null) {
            return response()->json([
                'success' => false,
                'message' => $paidError,
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

    /**
     * 商户归属校验：商户定制功能（features.merchant_id 非空）只能绑到
     * 绑定了同一商户号的群；机器人级绑定一律拒绝——商户功能按群商户号路由，
     * 机器人级配了也不生效。返回拒绝文案；null = 放行。
     */
    protected function merchantBindError(array $featureItems, int $botId, int $chatId): ?string
    {
        $featureIds = array_values(array_unique(array_filter(array_map(
            fn ($item) => (int) ($item["feature_id"] ?? 0),
            $featureItems
        ))));

        if ($featureIds === []) {
            return null;
        }

        $merchants = Features::query()
            ->whereIn("id", $featureIds)
            ->whereNotNull("merchant_id")
            ->where("merchant_id", "!=", "")
            ->pluck("merchant_id", "id");

        if ($merchants->isEmpty()) {
            return null;
        }

        if ($chatId === 0) {
            $names = Features::query()->whereIn("id", array_keys($merchants->all()))->pluck("name")->all();

            return "商户定制功能（" . implode("、", $names) . "）请在群级绑定：这类功能按群的商户号路由，机器人级绑定不生效";
        }

        $groupMid = "";
        try {
            $groupMid = trim((string) (GroupConfigs::merchantIdOf($chatId) ?? ""));
        } catch (\Throwable) {
            // 配置读不出来按「群没绑商户」处理
        }

        foreach ($merchants as $featureId => $mid) {
            if ($mid === $groupMid) {
                continue;
            }

            $name = (string) (Features::query()->whereKey((int) $featureId)->value("name") ?? $featureId);

            return "功能「{$name}」属于商户 {$mid}，当前群绑定的商户是 " . ($groupMid !== "" ? $groupMid : "（未绑定，先用 /bd 绑定）") . "，不允许绑定其它商户的插件功能";
        }

        return null;
    }

    /**
     * 付费功能绑定前置校验，返回拒绝文案；null = 放行
     *
     * 判的是「机器人归属人在有效套餐期内」，与执行期 FeatureExecutor 的判定同源
     * （ServiceExpiry::hasPaidAccess，5 分钟缓存）。
     */
    protected function paidBindError(array $featureItems, int $botId): ?string
    {
        if ($botId <= 0 || MerchantContext::isPlatformSuperAdmin()) {
            return null;
        }

        $featureIds = array_values(array_unique(array_filter(array_map(
            fn ($item) => (int) ($item['feature_id'] ?? 0),
            $featureItems
        ))));

        if ($featureIds === []) {
            return null;
        }

        $paidNames = Features::query()
            ->whereIn('id', $featureIds)
            ->where('access_level', 'paid')
            ->pluck('name')
            ->all();

        if ($paidNames === []) {
            return null;
        }

        $creatorId = (int) (Bots::query()->whereKey($botId)->value('creator_id') ?? 0);
        $owner = $creatorId > 0 ? User::query()->find($creatorId) : null;

        if ($owner !== null && ($owner->isSuperAdmin() || ServiceExpiry::hasPaidAccess($owner))) {
            return null;
        }

        return '以下功能为付费功能，归属账号暂无有效套餐：' . implode('、', $paidNames);
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
