<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\RealMan;

use danog\MadelineProto\Exception;
use Modules\Telegram\Jobs\TelegramApiOperateFeatureJob;

/**
 * 给指定用户（私聊）发消息
 *
 * 与 SendMsgToGroups 类似：把 chatIds 当作「目标用户 peer 列表」，
 * 逐个派发 TelegramApiOperateFeatureJob（text / media）。
 */
class SendMsgToPerson extends AbstractRealManFeature
{
    /**
     * @throws \Throwable
     */
    public function handle(?string $type, array $params): void
    {
        $peers = $params['chatIds'] ?? [];
        if (empty($peers)) {
            throw new Exception('chatIds is empty');
        }

        foreach ($peers as $peer) {
            $payload = $params;
            $payload['chat_id'] = $peer;
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
