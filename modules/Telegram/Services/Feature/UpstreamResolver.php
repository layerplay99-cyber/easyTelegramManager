<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Illuminate\Database\Eloquent\Collection;
use Modules\Telegram\Models\BotThirdConfig;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Models\FeaturesBinds;
use Modules\Telegram\Models\FeatureUpstreamEndpoint;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Models\ThirdConfigFeature;

/**
 * 上游解析唯一入口
 *
 * 以前「绑定级 > 机器人级 > 功能 config.upstream_id」这套优先级散落在
 * BaseCustomFeature / HttpRequestDriver / WalletService / InteractiveClickDriver
 * 等 8 处，各写一份还不一致。这里统一收口：
 *
 *   bot_third_configs（机器人候选池，多选）
 *     ∩ third_config_features（上游声明支持的功能）
 *     → features_binds.third_config_id（每个功能落到具体某一个上游）
 *
 * 运行时不强制校验「上游是否声明支持该功能」（那是后台保存时的约束），
 * 只校验「这个上游确实绑在该机器人上」，避免串到别的机器人的上游。
 * 候选池为空的历史机器人仍用 bots.third_config_id 镜像，不破坏存量。
 */
class UpstreamResolver
{
    /** 机器人绑定的上游 id 列表 */
    public function candidateIds(int $botId): array
    {
        return BotThirdConfig::query()
            ->where('bot_id', $botId)
            ->orderByDesc('is_default')
            ->pluck('third_config_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    public function candidateConfigs(int $botId): Collection
    {
        $ids = $this->candidateIds($botId);

        return $ids === []
            ? new Collection()
            : ThirdApiConfig::query()->whereIn('id', $ids)->get();
    }

    /** 某机器人、某功能允许选的上游 = 候选池 ∩ 声明支持该功能的上游 */
    public function allowedIds(int $botId, int $featureId): array
    {
        $botIds = $this->candidateIds($botId);

        if ($botIds === []) {
            return [];
        }

        $featureIds = ThirdConfigFeature::query()
            ->where('feature_id', $featureId)
            ->whereIn('third_config_id', $botIds)
            ->pluck('third_config_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return array_values($featureIds);
    }

    public function allowedConfigs(int $botId, int $featureId): Collection
    {
        $ids = $this->allowedIds($botId, $featureId);

        return $ids === []
            ? new Collection()
            : ThirdApiConfig::query()->whereIn('id', $ids)->get(['id', 'name', 'api_url']);
    }

    public function isAssignable(int $botId, int $featureId, int $thirdConfigId): bool
    {
        return $thirdConfigId > 0
            && in_array($thirdConfigId, $this->allowedIds($botId, $featureId), true);
    }

    /**
     * 保存功能绑定时的强校验：
     *   - 官方 API / 本地功能（needs_upstream=false）不允许带上游
     *   - 需要上游的功能，指定的上游必须属于 ①∩②
     */
    public function assertAssignable(int $botId, int $featureId, ?int $thirdConfigId): void
    {
        $feature = Features::query()->find($featureId);

        if (! $feature) {
            throw new \InvalidArgumentException('功能不存在');
        }

        $needs = (bool) $feature->needs_upstream;

        if (! $needs) {
            if ($thirdConfigId) {
                throw new \InvalidArgumentException('「'.$feature->name.'」走官方 API / 本地数据，不允许绑定上游');
            }

            return;
        }

        if (! $thirdConfigId || ! $this->isAssignable($botId, $featureId, $thirdConfigId)) {
            throw new \InvalidArgumentException('「'.$feature->name.'」必须指定一个已绑定该机器人、且已声明支持此功能的上游');
        }
    }

    /**
     * 运行时解析出上游 id（返回 0 表示没配上游，由调用方按原有方式报错）
     */
    public function resolveId(?FeaturesBinds $bind, ?Bots $bot, ?Features $feature = null): int
    {
        $id = (int) ($bind?->third_config_id ?? 0);

        if ($id <= 0 && $bot) {
            $id = (int) (BotThirdConfig::query()
                ->where('bot_id', $bot->id)
                ->where('is_default', true)
                ->value('third_config_id') ?? 0);

            if ($id <= 0) {
                // 候选池还没维护过时沿用机器人上的默认上游镜像
                $id = (int) ($bot->third_config_id ?? 0);
            }
        }

        if ($id > 0 && $bot) {
            $candidates = $this->candidateIds($bot->id);

            // 候选池为空 = 历史机器人，不做校验；有候选池就必须命中，防止串上游
            if ($candidates !== [] && ! in_array($id, $candidates, true)) {
                return 0;
            }
        }

        return $id;
    }

    /** 上游已绑定的功能 */
    public function boundFeatureIds(int $thirdConfigId): array
    {
        return ThirdConfigFeature::query()
            ->where('third_config_id', $thirdConfigId)
            ->pluck('feature_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * 该上游在「上游接口配置」里允许出现的接口 code
     *
     * 由它绑定功能所声明的 upstreamEndpoints 汇总而来；没绑功能就是空数组，
     * 页面会提示先去绑定功能，不再把全量平台接口一股脑列出来。
     */
    public function endpointCodesFor(int $thirdConfigId): array
    {
        $featureIds = $this->boundFeatureIds($thirdConfigId);

        if ($featureIds === []) {
            return [];
        }

        return FeatureUpstreamEndpoint::query()
            ->whereIn('feature_id', $featureIds)
            ->pluck('endpoint_code')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 把某个上游加进候选池并设为默认（不删其他候选）
     *
     * 列表页「就地改上游」等旧入口也调它，保证 bots.third_config_id
     * 与 bot_third_configs 永远一致，不会出现两个入口互相覆盖。
     */
    public function setDefault(int $botId, int $configId, int $tenantId = 0): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($botId, $configId, $tenantId) {
            BotThirdConfig::query()->where('bot_id', $botId)->update(['is_default' => false]);

            if ($configId > 0) {
                BotThirdConfig::query()->updateOrInsert(
                    ['bot_id' => $botId, 'third_config_id' => $configId],
                    ['is_default' => true, 'tenant_id' => $tenantId, 'created_at' => now(), 'updated_at' => now()]
                );
            }

            Bots::query()->where('id', $botId)->update([
                'third_config_id' => $configId > 0 ? $configId : null,
            ]);
        });
    }

    /** 同步机器人候选池，并维护 bots.third_config_id 这列默认上游镜像 */
    public function syncBotConfigs(int $botId, array $configIds, ?int $defaultId = null, int $tenantId = 0): void
    {
        $configIds = array_values(array_unique(array_filter(array_map('intval', $configIds))));

        if ($defaultId === null || ! in_array($defaultId, $configIds, true)) {
            $defaultId = $configIds[0] ?? 0;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($botId, $configIds, $defaultId, $tenantId) {
            BotThirdConfig::query()->where('bot_id', $botId)->delete();

            foreach ($configIds as $configId) {
                BotThirdConfig::query()->create([
                    'bot_id' => $botId,
                    'third_config_id' => $configId,
                    'is_default' => $configId === $defaultId,
                    'tenant_id' => $tenantId,
                ]);
            }

            Bots::query()->where('id', $botId)->update(['third_config_id' => $defaultId ?: null]);
        });
    }

    /** 保存上游的功能能力声明（官方 API 功能一律拒绝） */
    public function syncConfigFeatures(int $thirdConfigId, array $featureIds, int $tenantId = 0): void
    {
        $featureIds = array_values(array_unique(array_filter(array_map('intval', $featureIds))));

        if ($featureIds !== []) {
            $invalid = Features::query()
                ->whereIn('id', $featureIds)
                ->where('needs_upstream', false)
                ->pluck('name')
                ->all();

            if ($invalid !== []) {
                throw new \InvalidArgumentException('以下功能不经过上游，不允许绑定：'.implode('、', $invalid));
            }
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($thirdConfigId, $featureIds, $tenantId) {
            ThirdConfigFeature::query()->where('third_config_id', $thirdConfigId)->delete();

            foreach ($featureIds as $featureId) {
                ThirdConfigFeature::query()->create([
                    'third_config_id' => $thirdConfigId,
                    'feature_id' => $featureId,
                    'tenant_id' => $tenantId,
                ]);
            }
        });
    }
}
