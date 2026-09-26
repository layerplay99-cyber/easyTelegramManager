<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\RealMan;

use danog\MadelineProto\Exception;
use Modules\Telegram\Jobs\TelegramApiOperateFeatureJob;

/**
 * 快速回复消息
 *
 * 参数：
 *   - chatIds：目标会话 chat_id 列表
 *   - reply_to_msg_id：要回复的那条消息的 msg_id
 *   - text / mediaPath：回复内容（text 或 media）
 *
 * 实际发送由 TelegramApiOperateFeatureJob（type=reply）执行，带重试 / 超时。
 */
class FastReplayMsg extends AbstractRealManFeature
{
    /**
     * @throws \Throwable
     */
    public function handle(?string $type, array $params): void
    {
        $chatIds = $params['chatIds'] ?? [];
        $replyToMsgId = $params['reply_to_msg_id'] ?? null;

        if (empty($chatIds)) {
            throw new Exception('chatIds is empty');
        }

        if (!$replyToMsgId) {
            throw new Exception('reply_to_msg_id is required');
        }

        foreach ($chatIds as $chatId) {
            $payload = $params;
            $payload['chat_id'] = $chatId;
            TelegramApiOperateFeatureJob::dispatch(
                $this->sessionFile,
                $this->appId,
                $this->appHash,
                $payload,
                'reply',
            );
        }
    }
}
