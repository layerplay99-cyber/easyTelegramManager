<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\RealMan;

use danog\MadelineProto\Exception;
use Modules\Telegram\Jobs\TelegramApiOperateFeatureJob;

/**
 * 群内 @ 发送
 *
 * 与 SendMsgToGroups 类似：把 chatIds 当作「目标群列表」下发消息，
 * 额外支持 mention_ids（要 @ 的成员 user_id 列表）。
 * 实际的 @ 提及实体（messageEntityMentionName）由 TelegramApiOperateFeatureJob
 * 在发送前按 user_id 解析成员名并拼装，这里只负责拆群并派发任务。
 */
class SendMsgToGroupPerson extends AbstractRealManFeature
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

        // 要 @ 的成员：优先 mention_ids 数组，兼容单 user_id
        $mentionIds = $params['mention_ids'] ?? [];
        if (empty($mentionIds) && !empty($params['user_id'])) {
            $mentionIds = [$params['user_id']];
        }
        $mentionIds = array_values(array_filter(array_map('intval', (array) $mentionIds)));

        foreach ($chatIds as $chatId) {
            $payload = $params;
            $payload['chat_id'] = $chatId;
            $payload['mention_ids'] = $mentionIds;
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
