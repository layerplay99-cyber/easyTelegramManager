<?php
declare(strict_types=1);

namespace Modules\Telegram\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Telegram\Bot\Laravel\Facades\Telegram;

/**
 * Telegram 媒体服务 - 图片下载、S3 存储
 */
class TelegramMediaService
{
    private const LOG_FILE_LARAVEL = 'laravel';

    public function __construct(
        private LogMessageService $logMessageService,
    ) {}

    /**
     * 下载并返回图片内容
     *
     * @param string|null $token 该消息所属机器人的 api_token。多机器人部署必须传，
     *                            file_path 只有所属机器人的 token 才下载得到。
     */
    public function putImage($message, ?string $token = null): string|false
    {
        $url = $this->getImageUrl($message, $token);
        return $url ? file_get_contents($url) : false;
    }

    /**
     * 获取图片 URL
     *
     * @param string|null $token 见 putImage()
     *
     * ⚠️ 下面的 Telegram::getFile() 走的是 SDK 全局 BotManager，需要
     * config('telegram.bots.mybot.token')；本项目不配全局 token，多 bot 场景应改成
     * BotApiFactory::forBot($bot)->getFile()。目前这两个方法没有调用方，
     * 真正要用时一并改掉。
     */
    public function getImageUrl($message, ?string $token = null): string
    {
        try {
            $photo = collect($message->getPhoto())->last();
            $file = Telegram::getFile(['file_id' => $photo['file_id']]);
            return $this->buildDownloadUrl($file['file_path'], $token);
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                self::LOG_FILE_LARAVEL,
                [
                    'message' => $message->toArray(),
                    'error' => $e->getMessage(),
                ],
                'Failed to get image URL',
                'error'
            );
            return '';
        }
    }

    /**
     * Bot API 基础地址（不带 /bot）
     *
     * 部署了本地 Telegram Bot API Server 时由 .env 的 TELEGRAM_BASE_BOT_URL 切换，
     * 详见 docs/telegram-bot-api-server.md。
     */
    private function apiBaseUrl(): string
    {
        $base = rtrim((string) config('telegram.base_bot_url', 'https://api.telegram.org/bot'), '/');

        return rtrim(str_replace('/bot', '', $base), '/');
    }

    /**
     * 构建 Telegram 文件下载链接
     *
     * @param string $token 该消息所属机器人的 api_token，必填——多 bot 下没有全局 token，
     *                       file_path 只有所属机器人的 token 才下载得到
     */
    private function buildDownloadUrl(string $filePath, ?string $token = null): string
    {
        if (empty($token)) {
            Log::warning('下载 Telegram 文件缺少机器人 token，已跳过', ['file_path' => $filePath]);

            return '';
        }

        return $this->apiBaseUrl() . "/file/bot{$token}/{$filePath}";
    }

    /**
     * 从 Base64 编码存储图片到 S3
     */
    public function storeImageFromBase64Code(string $base64Image, ?string $customPath = null): array
    {
        try {
            if (preg_match('/^data:image\/(\w+);base64,/', $base64Image, $matches)) {
                $imageType = $matches[1];
                $base64Image = substr($base64Image, strpos($base64Image, ',') + 1);
            } else {
                $imageType = 'jpeg';
            }

            $imageData = base64_decode($base64Image);
            if ($imageData === false) {
                throw new \Exception('Invalid base64 string');
            }

            $fileName = Str::random(20) . '.' . $imageType;
            $filePath = $customPath ? rtrim($customPath, '/') . '/' . $fileName : 'uploads/' . $fileName;

            Storage::disk('s3')->put($filePath, $imageData, [
                'visibility' => 'public',
                'ContentType' => 'image/' . $imageType,
            ]);

            $url = Storage::disk('s3')->url($filePath);

            return [
                'success' => true,
                'url' => $url,
                'path' => $filePath,
                'filename' => $fileName,
            ];
        } catch (\Exception $e) {
            $this->logMessageService->createLaravelLog(
                self::LOG_FILE_LARAVEL,
                [
                    'base64Image' => substr($base64Image, 0, 30) . '...',
                    'customPath' => $customPath,
                    'error' => $e->getMessage(),
                ],
                'Failed to store image from base64',
                'error'
            );

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
