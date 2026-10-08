<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Models\ThirdApiEndpoints;
use Modules\Telegram\Services\Feature\Command\SlashCommandRegistry;
use Modules\Telegram\Services\Feature\CustomFeatureRegistry;
use Modules\Telegram\Services\Feature\DriverRegistry;
use Modules\Telegram\Services\Feature\PlatformEndpointRegistry;


class FeaturesController extends Controller
{
    public function __construct(
        protected readonly Features $model,
        protected readonly SlashCommandRegistry $commandRegistry
    ){}

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request): mixed
    {
        return $this->model->setBeforeGetList(function ($query) use ($request) {
            if ($category = $request->input('category')) {
                $query->where('category', $category);
            }

            if ($request->query('mode') === 'multiple') {
                $query->select(['id', 'name', 'category']);
            }

            return $query;
        })->getList()->through(function ($feature) {
            //附带展示用的中文名：执行器名 + 触发方式名。
            // 由后端直接给出，避免前端再发一次 drivers 请求导致显示英文 key。
            $driverLabels = DriverRegistry::labelMap();
            $feature->driver_label = $driverLabels[$feature->driver] ?? $feature->driver;
            $feature->trigger_label = $feature->trigger;

            return $feature;
        });
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
     * @return mixed
     */
    public function destroy(int|string $id): mixed
    {
        return $this->model->deleteBy($id);
    }

    /**
     * 代码里已实现的斜杠命令清单
     *
     * 后台新增命令时直接从这个接口选，不用手敲 handler：
     * 返回命令名、说明、用法、参数定义和配置项 schema（上游 API 等）。
     */
    public function slashCommands(): JsonResponse
    {
        $this->commandRegistry->discover();

        return response()->json([
            'status' => 'success',
            'data' => $this->commandRegistry->definitions(),
        ]);
    }

    /**
     * 可选执行器清单（后台表单的数据源）
     *
     * 返回每个执行器的 key / 名称 / 分组 / 触发方式 / 配置项 schema，
     * 前端据此自动渲染表单，不再手写 JSON、不再手敲 handler 类名。
     */
    public function drivers(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => DriverRegistry::definitions(),
        ]);
    }

    /**
     * 自定义功能清单（Drivers/Custom 目录下继承 BaseCustomFeature 的类）
     */
    public function customFeatures(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => app(CustomFeatureRegistry::class)->definitions(),
        ]);
    }

    /**
     * 配置表单的动态选项源
     *
     * 前端遇到 source 为数据库来源（三方配置/接口）的字段时，按 source 拉一次：
     *   GET telegram/features/options?source=third_api_configs
     *   GET telegram/features/options?source=third_api_endpoints
     */
    public function options(Request $request): JsonResponse
    {
        $source = (string) $request->query('source', '');

        $options = match ($source) {
            'third_api_configs' => ThirdApiConfig::query()
                ->get(['id', 'name'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])
                ->toArray(),
            'third_api_endpoints' => PlatformEndpointRegistry::options(),
                   'platform_endpoints' => PlatformEndpointRegistry::options(),
            'bot_groups' => BotGroups::query()
                ->where('enabled', true)
                ->get(['id', 'name', 'chat_id'])
                ->map(fn ($g) => [
                    'value' => $g->chat_id,
                    'label' => "{$g->name}（{$g->chat_id}）",
                ])
                ->toArray(),
            default => [],
        };

        return response()->json([
            'status' => 'success',
            'data' => $options,
        ]);
    }

    /**
     * 功能的命令列表
     *
     * 带 id：返回该功能下的命令（编辑单个功能时用）
     * 不带 id：返回「功能ID => 命令列表」的映射（列表页一次拿全部，避免 N+1 请求）
     */
    public function commands(null|int|string $id = null): JsonResponse
    {
        // 批量：一次返回全部命令映射
        if ($id === null || $id === '') {
            $grouped = FeatureCommands::query()
                ->orderByDesc('id')
                ->get()
                ->groupBy('feature_id');

            $map = [];

            foreach ($grouped as $featureId => $rows) {
                $map[(string) $featureId] = $rows->toArray();
            }

            return response()->json([
                'status' => 'success',
                'data' => $map,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => FeatureCommands::query()
                ->where('feature_id', $id)
                ->orderByDesc('id')
                ->get()
                ->toArray(),
        ]);
    }

    /**
     * 保存功能的命令列表
     */
    public function saveCommands(Request $request, int|string $id): JsonResponse
    {
        $commands = (array) $request->input('commands', []);

        // 先删掉该功能下未被提交的命令，实现「删改」语义
        $keep = collect($commands)->pluck('command')->filter()->all();

        FeatureCommands::query()
            ->where('feature_id', $id)
            ->when($keep, fn ($q) => $q->whereNotIn('command', $keep))
            ->delete();

        foreach ($commands as $command) {
            if (empty($command['command'])) {
                continue;
            }

            FeatureCommands::query()->updateOrCreate(
                ['command' => $command['command']],
                [
                    'feature_id' => $id,
                    'command' => $command['command'],
                    'usage' => $command['usage'] ?? null,
                    'description' => $command['description'] ?? null,
                    'scope' => $command['scope'] ?? 'group',
                    'permission' => $command['permission'] ?? 'all',
                    'params' => $command['params'] ?? [],
                    'reply_template' => $command['reply_template'] ?? null,
                    'enabled' => (bool) ($command['enabled'] ?? true),
                ]
            );
        }

        return $this->jsonSuccess();
    }
}
