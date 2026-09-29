<?php
declare(strict_types=1);

namespace Modules\Telegram\Jobs;

use danog\MadelineProto\API;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Telegram\Enums\LoginStatus;
use Modules\Telegram\Models\TelegramApiUsers;
use Modules\Telegram\Services\LogMessageService;
use Modules\Telegram\Services\User\UserApiFactory;

class ProcessQrLoginJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const LOG_FILE = 'process_qr_login_job';

    protected int $userId;
    protected string $sessionFile;
    protected int $appId;
    protected string $appHash;

    public int $timeout = 180; // 设置任务超时时间为 180 秒

    public function __construct(int $userId, string $sessionFile, int|string $appId, ?string $appHash)
    {
        $appId = (int) $appId;
        $appHash = (string) $appHash;
        $this->userId = $userId;
        $this->sessionFile = $sessionFile;
        $this->appId = $appId;
        $this->appHash = $appHash;
    }

    public function handle(LogMessageService $logMessageService): void
    {
        try {
            $logMessageService->createLaravelLog(
                self::LOG_FILE,
                ['user_id' => $this->userId],
                'ProcessQrLoginJob 开始处理',
                'info'
            );

            $user = TelegramApiUsers::find($this->userId);
            if (!$user) {
                $logMessageService->createLaravelLog(
                    self::LOG_FILE,
                    ['user_id' => $this->userId],
                    'ProcessQrLoginJob 用户不存在',
                    'error'
                );
                return;
            }

            // 检查会话文件是否存在
            if (!file_exists($this->sessionFile)) {
                $logMessageService->createLaravelLog(
                    self::LOG_FILE,
                    [
                        'user_id' => $this->userId,
                        'session_file' => $this->sessionFile
                    ],
                    'ProcessQrLoginJob 会话文件不存在',
                    'warning'
                );
                return;
            }

            // 重新创建 MadelineProto 实例（使用同一个 session 文件）
            $madelineService = app(UserApiFactory::class)->forSession(
                $this->sessionFile,
                $this->appId,
                $this->appHash
            );

            $api = $madelineService->getApi();

            $logMessageService->createLaravelLog(
                self::LOG_FILE,
                ['user_id' => $this->userId],
                'ProcessQrLoginJob 开始等待扫码',
                'info'
            );

            // qrLogin() 返回可空，拿不到二维码直接记日志退出，别在 null 上调用方法
            $qrLogin = $madelineService->qrLogin();

            if ($qrLogin === null) {
                $logMessageService->createLaravelLog(
                    self::LOG_FILE,
                    ['user_id' => $this->userId],
                    'ProcessQrLoginJob 获取二维码失败',
                    'warning'
                );

                return;
            }

            $qrLogin->waitForLoginOrQrCodeExpiration();

            // 检查登录是否完成
            $authorization = $api->getAuthorization();

            $logMessageService->createLaravelLog(
                self::LOG_FILE,
                [
                    'user_id' => $this->userId,
                    'authorization' => $authorization
                ],
                'ProcessQrLoginJob 扫码后授权状态',
                'info'
            );

            // 检查是否完全登录
            if ($authorization === API::LOGGED_IN) {
                $user->login_status = LoginStatus::LOGINED;
                $user->save();
                $logMessageService->createLaravelLog(
                    self::LOG_FILE,
                    ['user_id' => $this->userId],
                    'ProcessQrLoginJob 登录成功',
                    'info'
                );
                return;
            }

            // 检查是否需要2FA（等待密码）
            if ($authorization === API::WAITING_PASSWORD) {
                $user->login_status = LoginStatus::WAITINPUTCODE;
                $user->save();
                $logMessageService->createLaravelLog(
                    self::LOG_FILE,
                    ['user_id' => $this->userId],
                    'ProcessQrLoginJob 需要2FA',
                    'info'
                );
                return;
            }

            // 其他状态（可能是 QR 码过期，状态码 0）
            $logMessageService->createLaravelLog(
                self::LOG_FILE,
                [
                    'user_id' => $this->userId,
                    'authorization' => $authorization
                ],
                'ProcessQrLoginJob QR码可能已过期',
                'warning'
            );

        } catch (\Throwable $e) {
            $logMessageService->createLaravelLog(
                self::LOG_FILE,
                [
                    'user_id' => $this->userId,
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'trace' => $e->getTraceAsString()
                ],
                'ProcessQrLoginJob 异常',
                'error'
            );

            // 检查是否需要2FA
            $errorMsg = strtolower($e->getMessage());
            if (str_contains($errorMsg, 'password') ||
                str_contains($errorMsg, '2fa') ||
                str_contains($errorMsg, 'session_password_needed')) {

                $user = TelegramApiUsers::find($this->userId);
                if ($user) {
                    $user->login_status = LoginStatus::WAITINPUTCODE;
                    $user->save();
                    $logMessageService->createLaravelLog(
                        self::LOG_FILE,
                        ['user_id' => $this->userId],
                        'ProcessQrLoginJob 通过异常检测到需要2FA',
                        'info'
                    );
                }
            }
        }
    }

    /**
     * 任务失败处理
     */
    public function failed(\Throwable $exception): void
    {
        app(LogMessageService::class)->createLaravelLog(
            self::LOG_FILE,
            [
                'user_id' => $this->userId,
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ],
            'ProcessQrLoginJob 任务失败',
            'error'
        );
    }
}
