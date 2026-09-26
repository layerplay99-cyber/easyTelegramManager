<?php

namespace Modules\Telegram\Services\Feature\RealMan;

use Modules\Telegram\Interface\RealManFeature;
use Modules\Telegram\Models\TelegramApiUsers;

class CollectEmojiFromGroup implements RealManFeature
{

    public function handle(?string $type, array $params): void
    {
        if(!isset($params['chatIds']) || !isset($params['app_id'])) {
            return;
        }
        $chatId = $params['chatIds'][0];
        $appId = $params['app_id'];

        $session_file=TelegramApiUsers::query()
            ->where('app_id', $appId)
            ->value('session_file');

        \Illuminate\Support\Facades\Cache::put(
            'telegram_group_collect_emojis_'.$chatId,
            ['appId'=>$appId, 'session_file'=>$session_file],
            86400*30);
    }
}
