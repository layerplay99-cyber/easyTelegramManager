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
    private const TELEGRAM_API_URL = 'https://api.telegram.org';
    private const LOG_FILE_LARAVEL = 'laravel';

    public function __construct(
        private LogMessageService $logMessageService,
    ) {}

    /**
     * 下载并返回图片内容
     */
    public function putImage($message): string|false
    {
        $url = $this->getImageUrl($message);
        return $url ? file_get_contents($url) : false;
    }

    /**
     * 获取图片 URL
     */
    public function getImageUrl($message): string
    {
        try {
            $photo = collect($message->getPhoto())->last();
            $file = Telegram::getFile(['file_id' => $photo['file_id']]);
            return $this->buildDownloadUrl($file['file_path']);
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
     * 构建 Telegram 文件下载链接
     */
    private function buildDownloadUrl(string $filePath): string
    {
        $token = config('telegram.bots.mybot.token');
        return rtrim(self::TELEGRAM_API_URL, '/') . "/file/bot{$token}/{$filePath}";
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
