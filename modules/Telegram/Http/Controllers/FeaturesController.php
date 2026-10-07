<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Services\Feature\Command\SlashCommandRegistry;
use Modules\Telegram\Services\Feature\CustomFeatureRegistry;
use Modules\Telegram\Services\Feature\DriverRegistry;


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
     * 功能的命令列表（后台编辑功能时增删命令）
     */
    public function commands(int|string $id): JsonResponse
    {
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
