<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\User;

use Modules\Telegram\Models\TelegramApiUsers;
use Modules\Telegram\Services\Madeline\MadelineService;

/**
 * 真实用户 API 客户端工厂
 *
 * 统一创建 MadelineService（基于 MadelineProto 的真实用户账号客户端）。
 *
 * 之前 new MadelineService(...) 散落在 CollectServer / SyncCollect / CollectEmoji /
 * ProcessQrLoginJob / TelegramApiOperateFeatureJob / TelegramApiUserController，
 * 且参数不统一（有 1 参也有 3 参），漏传 app_id/app_hash 时行为不一致。
 *
 * 约定：真实用户账号的凭证三元组（session_file / app_id / app_hash）来自
 * TelegramApiUsers 模型。
 *
 * 用法：
 *   app(UserApiFactory::class)->forTelegramUser($user);                  // 从模型解析三元组
 *   app(UserApiFactory::class)->forSession($sessionFile, $appId, $hash);  // 显式路径
 *      （登录态已写入 session 文件时可只传路径，app_id/app_hash 留空）
 *
 * 新增真实用户侧功能时，统一从这里拿 MadelineService，构造逻辑（路径解析、
 * 重试、设置）只在 MadelineService 一处维护。
 */
class UserApiFactory
{
    public function forTelegramUser(TelegramApiUsers $user): MadelineService
    {
        return $this->forSession(
            $user->session_file,
            $user->app_id ? (int) $user->app_id : null,
            $user->app_hash ?: null
        );
    }

    public function forSession(string $sessionFile, ?int $appId = null, ?string $appHash = null): MadelineService
    {
        return new MadelineService($sessionFile, $appId, $appHash);
    }
}
