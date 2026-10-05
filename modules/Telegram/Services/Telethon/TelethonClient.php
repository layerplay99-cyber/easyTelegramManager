<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Telethon;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Telethon 服务（Python）的 HTTP 客户端。
 *
 * 所有 MTProto 原生能力都经这里转发给 telegram-py 容器，
 * 业务侧不再直接依赖 MadelineProto。
 */
class TelethonClient
{
    public function __construct(
        protected string $session,
        protected ?int $appId = null,
        protected ?string $appHash = null,
    ) {
    }

    public function getSession(): string
    {
        return $this->session;
    }

    /**
     * 统一的 POST 调用，返回解码后的数组。
     */
    public function post(string $path, array $payload = []): array
    {
        $payload = array_merge([
            'session'  => $this->session,
            'api_id'   => $this->appId,
            'api_hash' => $this->appHash,
        ], $payload);

        $response = Http::timeout((int) config('telethon.timeout', 120))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) config('telethon.base_url'), '/') . $path, $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'Telethon ' . $path . ' 请求失败: ' . $response->status() . ' ' . $response->body()
            );
        }

        return (array) $response->json();
    }
}
