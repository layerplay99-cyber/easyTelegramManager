<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Services\Feature\Command\SlashCommandRegistry;


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
}
