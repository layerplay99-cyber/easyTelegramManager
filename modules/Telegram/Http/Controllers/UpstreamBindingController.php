<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\Request;
use Modules\Permissions\Support\DataScope;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\BotThirdConfig;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Models\FeatureUpstreamEndpoint;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Models\ThirdConfigFeature;
use Modules\Telegram\Services\Feature\UpstreamResolver;

/**
 * 上游绑定管理
 *
 *  - 机器人 ↔ 多个上游（候选池 + 默认）
 *  - 上游 ↔ 功能（能力声明，只接受 needs_upstream 功能）
 */
class UpstreamBindingController extends Controller
{
    public function __construct(
        protected readonly UpstreamResolver $resolver
    ) {
    }

    /** 机器人可绑定上游：候选清单 + 已选 + 默认 */
    public function botUpstreams($id): array
    {
        $bot = Bots::query()->findOrFail($id);
        app(DataScope::class)->assertVisible($bot);

        $configs = app(DataScope::class)
            ->apply(ThirdApiConfig::query(), null, null)
            ->get(['id', 'name', 'api_url']);

        $rows = BotThirdConfig::query()->where('bot_id', $bot->id)->get();

        return [
            'bot' => ['id' => $bot->id, 'name' => $bot->name],
            'configs' => $configs->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => $c->name,
                'api_url' => $c->api_url,
            ])->values()->all(),
            'selected_ids' => $rows->pluck('third_config_id')->map(fn ($v) => (int) $v)->values()->all(),
            'default_id' => (int) ($rows->firstWhere('is_default', true)?->third_config_id ?? 0),
        ];
    }

    public function saveBotUpstreams($id, Request $request): mixed
    {
        $bot = Bots::query()->findOrFail($id);
        app(DataScope::class)->assertVisible($bot);

        $data = $request->validate([
            'third_config_ids' => 'required|array',
            'third_config_ids.*' => 'integer',
            'default_id' => 'nullable|integer',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['third_config_ids'])));

        $visibleIds = app(DataScope::class)
            ->apply(ThirdApiConfig::query(), null, null)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $invalid = array_values(array_diff($ids, $visibleIds));

        if ($invalid !== []) {
            // 不用 abort()：Catch 的异常处理器会把它包成 200 + code 10005，
            // 前端拿不到 HTTP 422。统一返回带 message 的 422。
            return response()->json(['message' => '包含无权使用的上游配置：'.implode('、', $invalid)], 422);
        }

        $this->resolver->syncBotConfigs(
            (int) $bot->id,
            $ids,
            isset($data['default_id']) ? (int) $data['default_id'] : null,
            (int) ($bot->tenant_id ?? 0)
        );

        return [
            'selected_ids' => $this->resolver->candidateIds((int) $bot->id),
            'default_id' => (int) (Bots::query()->where('id', $bot->id)->value('third_config_id') ?? 0),
        ];
    }

    /**
     * 功能配置弹框用的全量元信息（不分页）
     *
     * 不走 getList() 的分页：弹框右侧「已配置」来自绑定记录，本身没有
     * needs_upstream / description / 可选上游等字段，一次拿全量最可靠。
     */
    public function featureMeta(Request $request): array
    {
        $botId = (int) $request->input('bot_id', 0);

        $features = Features::query()
            ->orderBy('category')
            ->orderBy('id')
            ->get(['id', 'name', 'category', 'description', 'needs_upstream']);

        $endpointMap = FeatureUpstreamEndpoint::query()->get()->groupBy('feature_id');
        $candidateIds = $botId > 0 ? $this->resolver->candidateIds($botId) : [];

        $supportedMap = $candidateIds === []
            ? collect()
            : ThirdConfigFeature::query()->whereIn('third_config_id', $candidateIds)->get()->groupBy('feature_id');

        $configNames = $candidateIds === []
            ? collect()
            : ThirdApiConfig::query()->whereIn('id', $candidateIds)->pluck('name', 'id');

        return [
            'features' => $features->map(function ($f) use ($endpointMap, $supportedMap, $configNames, $botId) {
                $allowed = [];

                if ($botId > 0 && (bool) $f->needs_upstream) {
                    $allowed = ($supportedMap->get($f->id) ?? collect())
                        ->pluck('third_config_id')
                        ->map(fn ($v) => (int) $v)
                        ->unique()
                        ->values()
                        ->all();
                }

                return [
                    'id' => (int) $f->id,
                    'name' => $f->name,
                    'category' => $f->category,
                    'description' => $f->description,
                    'needs_upstream' => (bool) $f->needs_upstream,
                    'upstream_endpoints' => ($endpointMap->get($f->id) ?? collect())
                        ->pluck('endpoint_code')->values()->all(),
                    'allowed_config_ids' => $allowed,
                    'allowed_configs' => collect($allowed)
                        ->map(fn ($id) => ['id' => $id, 'name' => $configNames->get($id) ?? '#'.$id])
                        ->values()
                        ->all(),
                ];
            })->values()->all(),
        ];
    }

    /** 上游可绑定功能：只列 needs_upstream=1 的功能（官方 API 功能根本不出现） */
    public function configFeatures($id): array
    {
        $config = ThirdApiConfig::query()->findOrFail($id);
        app(DataScope::class)->assertVisible($config);

        $features = Features::query()
            ->where('needs_upstream', true)
            ->orderBy('category')
            ->orderBy('id')
            ->get(['id', 'name', 'category', 'description']);

        $endpointMap = FeatureUpstreamEndpoint::query()
            ->whereIn('feature_id', $features->pluck('id'))
            ->get()
            ->groupBy('feature_id');

        $selected = ThirdConfigFeature::query()
            ->where('third_config_id', $config->id)
            ->pluck('feature_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return [
            'config' => ['id' => $config->id, 'name' => $config->name],
            'features' => $features->map(fn ($f) => [
                'id' => (int) $f->id,
                'name' => $f->name,
                'category' => $f->category,
                'description' => $f->description,
                'endpoints' => ($endpointMap->get($f->id) ?? collect())
                    ->pluck('endpoint_code')->values()->all(),
            ])->values()->all(),
            'selected_ids' => $selected,
        ];
    }

    public function saveConfigFeatures($id, Request $request): mixed
    {
        $config = ThirdApiConfig::query()->findOrFail($id);
        app(DataScope::class)->assertVisible($config);

        $data = $request->validate([
            'feature_ids' => 'required|array',
            'feature_ids.*' => 'integer',
        ]);

        try {
            $this->resolver->syncConfigFeatures(
                (int) $config->id,
                array_map('intval', $data['feature_ids']),
                (int) ($config->tenant_id ?? 0)
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return [
            'selected_ids' => $this->resolver->boundFeatureIds((int) $config->id),
            'endpoint_codes' => $this->resolver->endpointCodesFor((int) $config->id),
        ];
    }
}
