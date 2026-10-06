<?php
declare(strict_types=1);

namespace Modules\Telegram\Services;

use danog\MadelineProto\Exception;
use Illuminate\Support\Facades\Storage;
use Modules\Telegram\Enums\LoginStatus;
use Modules\Telegram\Jobs\SyncCollect;
use Modules\Telegram\Services\Telethon\TelegramUserApi;
use Modules\Telegram\Services\User\UserApiFactory;
use Throwable;

class CollectServer
{
    private const TELEGRAM_DIRECTORY = 'telegram';
    private const SESSION_FILE_PREFIX = 'session_';
    private const SESSION_FILE_EXTENSION = '.madeline';
    private const QR_CODE_SIZE = 400;
    private const QR_LOGIN_CHECK_DELAY = 2;
    private const LOG_FILE = 'collect_server';

    protected TelegramUserApi $MadelineProto;

    public function __construct(
        protected LogMessageService $logMessageService
    ) {}

    /**
     * 生成 QR 码登录（非阻塞）
     *
     * @throws Exception|Throwable
     */
    public function appLogin($users, $phone): array
    {
        if (!Storage::exists(self::TELEGRAM_DIRECTORY)) {
            Storage::makeDirectory(self::TELEGRAM_DIRECTORY);
        }

        $sessionPath = $this->buildSessionPath($phone);

        try {
            $this->MadelineProto = app(UserApiFactory::class)->forSession(
                $sessionPath,
                (int)$users->app_id,
                $users->app_hash
            );

            $authorization = $this->MadelineProto->getAuthorization();

            if ($authorization === 3) {
                $this->updateUserLoginStatus($users, LoginStatus::LOGINED, $sessionPath);
                return [
                    'logged_in' => true,
                    'message' => '已登录'
                ];
            }

            if ($authorization === 2) {
                $this->updateUserLoginStatus($users, LoginStatus::WAITINPUTCODE, $sessionPath);
                return [
                    'logged_in' => false,
                    'require_2fa' => true,
                    'message' => '需要输入两步验证密码'
                ];
            }

            $qrSvg = $this->MadelineProto->getQrSvg();

            if (!$qrSvg) {
                $authorization = $this->MadelineProto->getAuthorization();
                if ($authorization === 2) {
                    $this->updateUserLoginStatus($users, LoginStatus::WAITINPUTCODE, $sessionPath);
                    return [
                        'logged_in' => false,
                        'require_2fa' => true,
                        'message' => '需要输入两步验证密码'
                    ];
                }
                throw new \Exception('生成 QR 码失败，请检查会话状态');
            }

            $this->updateUserLoginStatus($users, LoginStatus::WAITINPUTCODE, $sessionPath);

            // 传递相对路径给 Job
            \Modules\Telegram\Jobs\ProcessQrLoginJob::dispatch(
                $users->id,
                $sessionPath,
                $users->app_id,
                $users->app_hash
            )->delay(now()->addSeconds(self::QR_LOGIN_CHECK_DELAY));

            return [
                'qr_svg' => $qrSvg,
                'logged_in' => false,
                'message' => '请使用 Telegram 扫描二维码'
            ];

        } catch (\Exception $e) {
            $this->logMessageService->createLaravelLog(
                self::LOG_FILE,
                [
                    'user_id' => $users->id,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
                'appLogin 异常',
                'error'
            );
            throw $e;
        }
    }

    /**
     * 检查登录状态（供前端轮询）
     *
     * @throws Exception|Throwable
     */
    public function checkLoginStatus($users): array
    {
        if (!$users->session_file) {
            return [
                'is_logged_in' => false,
                'status' => 'waiting',
                'message' => '会话文件不存在'
            ];
        }

        try {
            $this->MadelineProto = app(UserApiFactory::class)->forTelegramUser($users);

            $authorization = $this->MadelineProto->getAuthorization();

            // 完全登录状态（状态码 3）
            if ($authorization === 3) {
                $users->login_status = LoginStatus::LOGINED;
                $users->save();
                return [
                    'is_logged_in' => true,
                    'status' => 'logged_in',
                    'message' => '登录成功'
                ];
            }

            // 等待密码状态（状态码 2） - 需要两步验证
            if ($authorization === 2) {
                $users->login_status = LoginStatus::WAITINPUTCODE;
                $users->save();
                return [
                    'is_logged_in' => false,
                    'status' => 'require_2fa',
                    'require_2fa' => true,
                    'message' => '需要输入两步验证密码'
                ];
            }

            // 等待扫码或其他状态
            return [
                'is_logged_in' => false,
                'status' => 'waiting',
                'message' => '等待扫码或验证'
            ];

        } catch (\Exception $e) {
            // 检查异常消息中是否包含2FA关键字
            if ($this->is2FAException($e)) {
                $users->login_status = LoginStatus::WAITINPUTCODE;
                $users->save();

                return [
                    'is_logged_in' => false,
                    'status' => 'require_2fa',
                    'require_2fa' => true,
                    'message' => '需要输入两步验证密码'
                ];
            }

            return [
                'is_logged_in' => false,
                'status' => 'error',
                'message' => '检查登录状态失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 获取 QR Code
     *
     * @throws Exception|Throwable
     */
    public function getQrCode($users): array
    {
        if (!$users->session_file) {
            throw new Exception('Session file not found');
        }

        $this->MadelineProto = app(UserApiFactory::class)->forTelegramUser($users);

        return [
            'logged_in' => false,
            'svg' => $this->MadelineProto->getQrSvg(),
        ];
    }

    /**
     * 等待 QR Code 登录或获取新的 QR Code
     *
     * @throws Exception|Throwable
     */
    public function waitQrCodeOrLogin($users): array
    {
        if (!$users->session_file) {
            throw new Exception('Session file not found');
        }

        $this->MadelineProto = app(UserApiFactory::class)->forTelegramUser($users);

        // 检查是否已登录
        if ($this->MadelineProto->isLoggedIn()) {
            $users->login_status = LoginStatus::LOGINED;
            $users->save();
            return ['logged_in' => true];
        }

        try {
            // 等待扫码成功 / 需要 2FA / QR 过期换新码
            $result = $this->MadelineProto->waitAuthorization(60);

            if (!empty($result['require_2fa'])) {
                $users->login_status = LoginStatus::WAITINPUTCODE;
                $users->save();
                return [
                    'logged_in' => false,
                    'require_2fa' => true,
                    'message' => '需要输入两步验证密码'
                ];
            }

            if (!empty($result['logged_in'])) {
                $users->login_status = LoginStatus::LOGINED;
                $users->save();
                return ['logged_in' => true];
            }

            // QR Code 过期，返回新的
            return [
                'logged_in' => false,
                'svg' => $result['svg'] ?? '',
            ];
        } catch (\Exception $e) {
            // 检查是否需要两步验证
            if ($this->is2FAException($e)) {
                $users->login_status = LoginStatus::WAITINPUTCODE;
                $users->save();
                return [
                    'logged_in' => false,
                    'require_2fa' => true,
                    'message' => '需要输入两步验证密码'
                ];
            }
            throw $e;
        }
    }

    /**
     * 完成两步验证登录
     *
     * @throws Exception|Throwable
     */
    public function complete2faLogin($users, string $password): bool
    {
        if (!$users->session_file) {
            throw new Exception('Session file not found');
        }

        $this->MadelineProto = app(UserApiFactory::class)->forTelegramUser($users);

        try {
            $this->MadelineProto->complete2faLogin($password);
            $users->login_status = LoginStatus::LOGINED;
            $users->save();
            return true;
        } catch (\Exception $e) {
            $this->logMessageService->createLaravelLog(
                self::LOG_FILE,
                [
                    'user_id' => $users->id,
                    'message' => $e->getMessage(),
                ],
                'complete2faLogin 失败',
                'error'
            );
            throw new Exception('两步验证密码错误: ' . $e->getMessage());
        }
    }

    /**
     * 完成验证码登录
     *
     * @throws Exception|Throwable
     */
    public function completeLogin($users, string $code): bool
    {
        if (!$users->session_file) {
            throw new Exception('Session file not found');
        }

        $this->MadelineProto = app(UserApiFactory::class)->forTelegramUser($users);
        $this->MadelineProto->completeLogin($code);
        $users->login_status = LoginStatus::LOGINED;
        $users->save();
        return true;
    }

    /**
     * 注销登录
     *
     * @throws Exception|Throwable
     */
    public function logout($users): array
    {
        if (!$users->session_file) {
            throw new Exception('Session file not found');
        }

        try {
            // Telethon 的 session 由 Python 服务持有，先通知它登出
            try {
                $this->MadelineProto = app(UserApiFactory::class)->forTelegramUser($users);
                $this->MadelineProto->logout();
            } catch (\Throwable $e) {
                // 远程登出失败不阻断本地状态清理
            }

            $sessionFilePath = storage_path($users->session_file);
            if (file_exists($sessionFilePath)) {
                if (is_dir($sessionFilePath)) {
                    $this->deleteDirectory($sessionFilePath);
                } else {
                    @unlink($sessionFilePath);
                }
            }

            $users->login_status = LoginStatus::NOTLOGIN;
            $users->session_file = null;
            $users->save();

            return [
                'success' => true,
                'message' => '注销登录成功'
            ];
        } catch (\Exception $e) {
            $this->logMessageService->createLaravelLog(
                self::LOG_FILE,
                [
                    'user_id' => $users->id,
                    'message' => $e->getMessage(),
                ],
                'logout 失败',
                'error'
            );
            throw new Exception('注销登录失败: ' . $e->getMessage());
        }
    }

    /**
     * 同步采集数据
     */
    public function syncCollect($users, int $days = 3): void
    {
        if (!$users->session_file) {
            throw new Exception('Session file not found');
        }

        // 不再把 MadelineService 实例塞进 job（无法序列化）。
        // SyncCollect 现在只接收可序列化的 TelegramApiUsers + days，自身在 handle() 里重建连接。
        SyncCollect::dispatch($users, $days);
    }

    /**
     * 递归删除目录及其所有内容
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return @unlink($dir);
        }

        $items = scandir($dir);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }

    /**
     * 构建会话文件路径
     */
    private function buildSessionPath(string $phone): string
    {
        return 'app/' . self::TELEGRAM_DIRECTORY . '/' . self::SESSION_FILE_PREFIX . $phone . self::SESSION_FILE_EXTENSION;
    }

    /**
     * 更新用户登录状态
     */
    private function updateUserLoginStatus($users, int $status, string $sessionPath): void
    {
        $users->login_status = $status;
        $users->session_file = $sessionPath;
        $users->save();
    }

    /**
     * 检查是否为 2FA 相关异常
     */
    private function is2FAException(\Exception $e): bool
    {
        $errorMsg = strtolower($e->getMessage());
        return str_contains($errorMsg, 'session_password_needed') ||
               str_contains($errorMsg, 'password') ||
               str_contains($errorMsg, '2fa');
    }
}
