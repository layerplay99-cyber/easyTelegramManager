<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\RealMan;

use danog\MadelineProto\Exception;
use Modules\Telegram\Jobs\TelegramApiOperateFeatureJob;

/**
 * 给群组群发消息
 *
 * 沿用 TelegramApiOperateFeatureJob 作为实际发送执行器（带重试 / 超时 / 可序列化），
 * 这里只负责把 chatIds 拆成多个任务派发。
 */
class SendMsgToGroups extends AbstractRealManFeature
{
    /**
     * @throws \Throwable
     */
    public function handle(?string $type, array $params): void
    {
        $chatIds = $params['chatIds'] ?? [];
        if (empty($chatIds)) {
            throw new Exception('chatIds is empty');
        }

        foreach ($chatIds as $chatId) {
            $payload = $params;
            $payload['chat_id'] = $chatId;
            TelegramApiOperateFeatureJob::dispatch(
                $this->sessionFile,
                $this->appId,
                $this->appHash,
                $payload,
                $type ?: 'text',
            );
        }
    }
}
