<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Http\Requests\PhoneRequest;
use Modules\Telegram\Jobs\ImportPhonesJob;
use Modules\Telegram\Models\Phones;
use Symfony\Component\HttpFoundation\BinaryFileResponse;


class PhoneController extends Controller
{
    public function __construct(
        protected readonly Phones $model
    ) {}

    public function index(): mixed
    {
        return $this->model->getList();
    }

    public function importProgress(Request $request): array
    {
        $taskId = $request->input('task_id');
        $progress = Cache::get($taskId);

        return $progress ?? ['status' => 'not_found'];
    }

    public function store(PhoneRequest $request): mixed
    {
        return $this->model->storeBy($request->all());
    }

    public function show(int|string $id): mixed
    {
        return $this->model->firstBy($id);
    }

    public function update(int|string $id, PhoneRequest $request): mixed
    {
        return $this->model->updateBy($id, $request->all());
    }

    public function destroy(int|string $id): mixed
    {
        return $this->model->deleteBy($id);
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv'
        ]);

        $file = $request->file('file')->store('imports');
        $taskId = uniqid('import_', true);

        // 进度初始化
        Cache::put(
            key: $taskId,
            value: ['total' => 0, 'current' => 0, 'status' => 'pending'],
            ttl: 3600
        );

        // 派发队列任务
        ImportPhonesJob::dispatch($file, $taskId, $this->getLoginUserId());

        return response()->json([
            'success' => true,
            'task_id' => $taskId
        ]);
    }

    /**
     * 下载导入模板
     *
     * @return BinaryFileResponse
     */
    public function downloadPhoneTemplate()
    {
        // 创建模板数据
        $templateData = [
            [
                'resource_id' => "1",
                'url' => "2",
                'username' => "3",
                'name' => "4",
                'country' => "5",
                'works' => "6",
                'age' => "7",

            ]
        ];

        return collect($templateData)->download(['RESOURCEID', '个人首页地址', '用户名', '姓名', '国家', '工作', '年龄']);
    }

    /**
     * @return void
     */
    public function export()
    {
        $query = $this->model->setBeforeGetList(function ($query){
            if ($this->getLoginUser()->isSuperAdmin()) {
                return $query;
            }

            $query = $query->where('department_id', $this->getLoginUser()->department_id);
            if($this->getLoginUser()->parent_id === config('catch.super_admin')){
                return $query;
            }
            $query = $query->where('creator_id', $this->getLoginUserId());
            return $query;
        });
        // 原来 select 的 resource_id / url / username / name / country / works / age
        // 在 phones 表里根本不存在（迁移只建了 id/phone/status/scantime/creator_id/时间戳），
        // 一调用就报 Unknown column；map 里还取了不存在的 source_id，导出列恒为 null。
        // 这里改为导出表里真实存在的字段，保证功能可用。
        return $query->select('id', 'phone', 'status', 'scantime', 'created_at')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'phone' => $item->phone,
                    'status' => $item->status,
                    'scantime' => $item->scantime,
                    'created_at' => $item->created_at,
                ];
            })
            ->download(['ID', '手机号', '状态', '扫描时间', '创建时间']);
    }
}
