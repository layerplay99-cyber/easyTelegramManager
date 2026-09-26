<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\RealMan;

use danog\MadelineProto\Exception;
use Modules\Telegram\Jobs\TelegramApiOperateFeatureJob;

/**
 * 把用户踢出群组 / 频道
 *
 * 参数：
 *   - chatIds：目标群组 chat_id 列表（负数）
 *   - user_id：要踢出的 Telegram 用户 ID
 *
 * 实际踢人动作由 TelegramApiOperateFeatureJob（type=kick）执行，带重试 / 超时。
 */
class KickOutGroup extends AbstractRealManFeature
{
    /**
     * @throws \Throwable
     */
    public function handle(?string $type, array $params): void
    {
        $groupIds = $params['chatIds'] ?? [];
        $userId = $params['user_id'] ?? null;

        if (empty($groupIds)) {
            throw new Exception('chatIds is empty');
        }

        if (!$userId) {
            throw new Exception('user_id is required');
        }

        foreach ($groupIds as $groupId) {
            $payload = $params;
            $payload['chat_id'] = $groupId;
            TelegramApiOperateFeatureJob::dispatch(
                $this->sessionFile,
                $this->appId,
                $this->appHash,
                $payload,
                'kick',
            );
        }
    }
}
