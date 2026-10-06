<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\RealMan;

use Modules\Telegram\Interface\RealManFeature;
use Modules\Telegram\Services\Telethon\TelegramUserApi;
use Modules\Telegram\Services\User\UserApiFactory;

/**
 * 真实用户特性的抽象基类
 *
 * 统一保存凭证三元组（session_file / app_id / app_hash），
 * 并提供一个懒加载的 TelegramUserApi 客户端（$this->api()）。
 *
 * 约定：控制器用 TelegramApiUsers 的凭证三元组实例化，故构造函数签名固定为
 *   (string $sessionFile, $appId, $appHash)
 * 新增真实用户功能时，只需继承本类并实现 handle()，无需再重复写构造函数与 new MadelineService。
 */
abstract class AbstractRealManFeature implements RealManFeature
{
    protected string $sessionFile;
    protected ?int $appId;
    protected ?string $appHash;

    public function __construct(string $sessionFile, $appId = null, $appHash = null)
    {
        $this->sessionFile = $sessionFile;
        $this->appId = $appId ? (int) $appId : null;
        $this->appHash = $appHash ?: null;
    }

    /**
     * 构造真实用户客户端（懒加载）。子类直接调用即可，无需自己 new MadelineService。
     */
    protected function api(): TelegramUserApi
    {
        return app(UserApiFactory::class)->forSession($this->sessionFile, $this->appId, $this->appHash);
    }

    abstract public function handle(?string $type, array $params);
}
