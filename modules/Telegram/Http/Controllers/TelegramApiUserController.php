<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use danog\MadelineProto\Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Telegram\Models\TelegramApiUsers;
use Modules\Telegram\Services\CollectServer;
use Modules\Telegram\Services\Feature\RealMan\RealManFeatureRegistry;
use Modules\Telegram\Services\FeatureOperateService;
use Modules\Telegram\Services\Madeline\MadelineService;
use Modules\Telegram\Services\Madeline\SyncUserGroupService;


class TelegramApiUserController extends Controller
{
    public function __construct(
        protected readonly TelegramApiUsers $model,
        protected readonly CollectServer $collectServer,
        protected readonly FeatureOperateService $featureOperateService,
    ){}

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request): mixed
    {
        return $this->model->setBeforeGetList(function ($query) use ($request) {
            // 数据范围由 TelegramApiUsers 模型的 DataRange trait 自动生效
            if ($appId = $request->input('app_id')) {
                $query->where('app_id', $appId);
            }

            $query->with('featuresBinds.feature:id,handler,name')
                  ->withCount('servicePeoples');

            return $query;
        })->getList();
    }

    /**
     * @param Request $request
     * @return mixed
     * @throws \Throwable
     */
    public function store(Request $request): mixed
    {
        return DB::transaction(function () use ($request) {
            $result = $this->model->storeBy($request->all());

            // bindFeature 返回 void，单独调用
            $this->featureOperateService->bindFeature(
                chatId: '',
                botId: $request->input('app_id'),
                type: 'realMan'
            );

            return $result;
        });
    }

    /**
     * @param int|string $appId
     * @return mixed
     */
    public function show(int|string $appId): mixed
    {
        // 加归属校验，避免越权查看他人账号
        $query = $this->model->where('id', $appId);

        if (! $this->getLoginUser()->isSuperAdmin()) {
            $query->where('creator_id', $this->getLoginUserId());
        }

        return $query->firstOrFail();
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

    public function appLogin(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'userId' => 'required|integer',
            ]);

            $telegramUser = $this->getOwnedTelegramUser($validated['userId']);

            // 缓存登录会话
            $this->cacheLoginSession($validated['userId']);

            $loginResult = $this->collectServer->appLogin(
                users: $telegramUser,
                phone: $telegramUser->phone_number
            );

            $this->broadcastLoginStatus($validated['userId'], $telegramUser->login_status);

            return $this->jsonSuccess([
                'qr_svg' => $loginResult['qr_svg'] ?? null,
                'login_url' => $loginResult['login_url'] ?? null,
                'logged_in' => $loginResult['logged_in'] ?? false,
                'login_status' => $telegramUser->login_status,
            ]);

        } catch (Exception|\Throwable $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 检查登录状态（用于前端轮询）
     */
    public function checkLoginStatus(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'userId' => 'required|integer',
            ]);

            $telegramUser = $this->getOwnedTelegramUser($validated['userId']);
            $result = $this->collectServer->checkLoginStatus($telegramUser);

            // 刷新用户数据
            $telegramUser->refresh();

            $this->broadcastLoginStatus($validated['userId'], $telegramUser->login_status);

            return $this->jsonSuccess([
                'is_logged_in' => $result['is_logged_in'] ?? false,
                'require_2fa' => $result['require_2fa'] ?? false,
                'message' => $result['message'] ?? null,
                'waiting' => $result['waiting'] ?? false,
                'login_status' => $telegramUser->login_status,
            ]);

        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * MadelineProto QR Code 登录接口（GET 方法）
     * 处理 MadelineProto 内置 HTML 的 AJAX 请求
     */
    public function loginQrCode(Request $request): JsonResponse
    {
        try {
            $action = match(true) {
                $request->has('getQrCode') => 'getQrCode',
                $request->has('waitQrCodeOrLogin') => 'waitQrCodeOrLogin',
                default => null
            };

            if (!$action) {
                return $this->jsonError('Invalid action');
            }

            $userId = $request->query('userId') ?? Cache::get('telegram_login_last');

            if (!$userId || !Cache::has("telegram_login_{$userId}")) {
                return $this->jsonError($userId ? 'No login session found' : 'No userId provided');
            }

            $telegramUser = $this->getOwnedTelegramUser($userId);

            return match($action) {
                'getQrCode' => response()->json(
                    $this->collectServer->getQrCode($telegramUser)
                ),
                'waitQrCodeOrLogin' => $this->handleWaitQrCodeOrLogin($telegramUser, (int)$userId),
            };

        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 完成两步验证登录
     */
    public function complete2faLogin(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'userId' => 'required|integer',
                'password' => 'required|string',
            ]);

            $telegramUser = $this->getOwnedTelegramUser($validated['userId']);
            $this->collectServer->complete2faLogin($telegramUser, $validated['password']);

            $this->broadcastLoginStatus($validated['userId'], $telegramUser->login_status);

            return $this->jsonSuccess([], '登录成功');

        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    public function completeLogin(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'userId' => 'required|integer',
                'code' => 'required|string',
            ]);

            $telegramUser = $this->getOwnedTelegramUser($validated['userId']);
            $this->collectServer->completeLogin($telegramUser, $validated['code']);

            $this->broadcastLoginStatus($validated['userId'], $telegramUser->login_status);

            return $this->jsonSuccess();

        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'userId' => 'required|integer',
            ]);

            $telegramUser = $this->getOwnedTelegramUser($validated['userId']);
            $this->collectServer->logout($telegramUser);

            $this->broadcastLoginStatus($validated['userId'], $telegramUser->login_status);

            return $this->jsonSuccess();

        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * Operate Feature
     * @throws \Exception|\Throwable
     */
    public function operateFeature(Request $request, string $type): JsonResponse
    {
        try {
            $validated = $request->validate([
                'app_id' => 'required|string',
                'chatIds' => 'required|array',
                'text' => 'nullable|string',
                'mediaPath' => 'nullable|string',
                'buttons' => 'nullable|array',
                'operation' => 'required|string',
                'user_id' => 'nullable|integer',
                'reply_to_msg_id' => 'nullable|integer',
                'mention_ids' => 'nullable|array',
                'mention_ids.*' => 'integer',
            ]);

            // 先查找 TelegramApiUser（非超管只能操作自己名下的账号）
            $usersQuery = $this->model->where('app_id', $validated['app_id']);

            if (! $this->getLoginUser()->isSuperAdmin()) {
                $usersQuery->where('creator_id', $this->getLoginUserId());
            }

            $users = $usersQuery->firstOrFail();

            // 使用 bot_id (即 app_id 的值) 来查询 features_binds
            $users->load([
                'featuresBinds' => function($query) use ($validated, $users) {
                    $query->where('bot_id', $users->app_id)
                        ->where('enabled', 1)
                        ->whereHas('feature', fn($q) => $q->where([
                            'enabled' => 1,
                            'handler' => $validated['operation']
                        ]))
                        ->with(['feature' => fn($q) => $q->where([
                            'enabled' => 1,
                            'handler' => $validated['operation']
                        ])]);
                }
            ]);

            if ($users->featuresBinds->isEmpty() || !$users->featuresBinds->first()->feature) {
                return $this->jsonError('功能未启用');
            }

            $features = RealManFeatureRegistry::allFeatures();

            if (!isset($features[$validated['operation']])) {
                return $this->jsonError('功能不存在');
            }

            $class = $features[$validated['operation']];
            $featureOperate = new $class(
                $users->session_file,
                $users->app_id,
                $users->app_hash
            );

            $featureOperate->handle($type, $validated);

            return $this->jsonSuccess();

        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * @throws \Exception|\Throwable
     */
    public function syncGroups(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'app_id' => 'required|string',
            ]);

            $sessionFileQuery = $this->model
                ->where('app_id', $validated['app_id']);

            if (! $this->getLoginUser()->isSuperAdmin()) {
                $sessionFileQuery->where('creator_id', $this->getLoginUserId());
            }

            $sessionFile = $sessionFileQuery->value('session_file');

            if (!$sessionFile) {
                return $this->jsonError('未找到会话文件');
            }

            $madelineService = app(\Modules\Telegram\Services\User\UserApiFactory::class)->forSession($sessionFile);

            if (app(SyncUserGroupService::class)->syncBotGroups($madelineService, $validated['app_id'], $this->getLoginUserId())) {
                return $this->jsonSuccess();
            }

            return $this->jsonError('同步失败');

        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 取「归属当前登录用户」的 TelegramApiUsers（超管不受限）
     *
     * 原来这些方法直接 findOrFail(userId)，任意登录用户传别人的 id
     * 就能登录 / 操作 / 登出他人的 Telegram 账号。
     */
    private function getOwnedTelegramUser(int $id): TelegramApiUsers
    {
        $query = $this->model->where('id', $id);

        if (! $this->getLoginUser()->isSuperAdmin()) {
            $query->where('creator_id', $this->getLoginUserId());
        }

        return $query->firstOrFail();
    }

    /**
     * 统一成功响应
     */
    private function jsonSuccess(array $data = [], string $message = ''): JsonResponse
    {
        $payload = ['status' => 'success'];
        if ($message) {
            $payload['message'] = $message;
        }
        return response()->json(array_merge($payload, $data));
    }

    /**
     * 统一错误响应
     */
    private function jsonError(string $message, int $code = 400): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], $code);
    }

    private function cacheLoginSession(int $userId): void
    {
        $expiry = now()->addMinutes(30);
        Cache::put("telegram_login_{$userId}", $userId, $expiry);
        Cache::put('telegram_login_last', $userId, $expiry);
    }

    private function broadcastLoginStatus(int $userId, int|string $loginStatus): void
    {
        // 确保 login_status 是整数类型
        $status = is_int($loginStatus) ? $loginStatus : (int)$loginStatus;
        broadcast(new \Modules\Telegram\Events\TelegramUserLoginStatusEvent($userId, $status));
    }

    /**
     * @throws \Throwable
     * @throws Exception
     */
    private function handleWaitQrCodeOrLogin(TelegramApiUsers $telegramUser, int $userId): JsonResponse
    {
        $result = $this->collectServer->waitQrCodeOrLogin($telegramUser);

        if ($result['logged_in'] ?? false) {
            Cache::forget("telegram_login_{$userId}");
            Cache::forget('telegram_login_last');
        }

        return response()->json($result);
    }

    /**
     * 从原 tusers 表迁移来的 Excel 导入（手机号字段名为 phone_number）
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv'
        ]);

        try {
            $file = $request->file('file');
            $data = $this->parseImportFile($file);

            $successCount = 0;
            $failedRows = [];

            foreach ($data as $index => $row) {
                try {
                    if (empty($row['phone_number']) || empty($row['app_id']) || empty($row['app_hash'])) {
                        $failedRows[] = [
                            'row' => $index + 2,
                            'error' => '手机号码、App ID 和 App Hash 不能为空'
                        ];
                        continue;
                    }

                    if ($this->model->where('phone_number', $row['phone_number'])
                        ->orWhere('app_id', $row['app_id'])
                        ->orWhere('app_hash', $row['app_hash'])
                        ->exists()) {
                        $failedRows[] = [
                            'row' => $index + 2,
                            'error' => '手机号码、App ID 或 App Hash 已存在'
                        ];
                        continue;
                    }

                    $this->model->create([
                        'phone_number' => $row['phone_number'],
                        'app_id' => $row['app_id'],
                        'app_hash' => $row['app_hash'],
                        'creator_id' => $this->getLoginUserId(),
                    ]);

                    $successCount++;
                } catch (\Exception $e) {
                    $failedRows[] = [
                        'row' => $index + 2,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return [
                'success' => true,
                'message' => "导入完成！成功导入 {$successCount} 条记录",
                'data' => [
                    'success_count' => $successCount,
                    'failed_count' => count($failedRows),
                    'failed_rows' => $failedRows
                ]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '导入失败：' . $e->getMessage()
            ];
        }
    }

    /**
     * 解析导入文件
     */
    private function parseImportFile($file): array
    {
        $extension = $file->getClientOriginalExtension();
        $data = [];

        if ($extension === 'csv') {
            $handle = fopen($file->path(), 'r');
            $headers = fgetcsv($handle);

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) >= count($headers)) {
                    $data[] = array_combine($headers, $row);
                }
            }
            fclose($handle);
        } else {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->path());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            if (count($rows) > 0) {
                $headers = array_shift($rows);
                foreach ($rows as $row) {
                    if (count($row) >= count($headers)) {
                        $data[] = array_combine($headers, $row);
                    }
                }
            }
        }
        return $data;
    }

    /**
     * 下载导入模板
     */
    public function downloadTemplate()
    {
        $templateData = [
            [
                'app_id' => "",
                'app_hash' => "",
                'phone_number' => "",
            ]
        ];

        return collect($templateData)->download(['app_id', 'app_hash', 'phone_number']);
    }

    /**
     * 导出
     */
    public function export()
    {
        return TelegramApiUsers::query()
            ->select('app_id', 'app_hash', 'phone_number', 'created_at')
            ->get()
            ->map(function ($item) {
                return [
                    'app_id' => $item->app_id,
                    'app_hash' => ' ' . $item->app_hash,
                    'phone_number' => ' ' . $item->phone_number,
                    'created_at' => $item->created_at
                ];
            })
            ->download(['app_id', 'app_hash', '手机号码', '创建时间']);
    }
}
