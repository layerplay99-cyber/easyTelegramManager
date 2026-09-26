<?php
declare(strict_types=1);

namespace Modules\Telegram\Services;

use Modules\Telegram\Models\Features;
use Modules\Telegram\Models\FeaturesBinds;

class FeatureOperateService
{
    private const DEFAULT_CREATOR_ID = 1;
    private const LOG_FILE = 'feature_operate_service';

    public function __construct(
        protected LogMessageService $logMessageService
    ) {}

    /**
     * 绑定功能到聊天
     *
     * @param int|string $chatId 聊天 ID
     * @param int $botId 机器人 ID
     * @param string $type 功能类型
     */
    public function bindFeature(int|string $chatId, int $botId, string $type): void
    {
        try {
            $features = Features::where('category', $type)
                ->where('enabled', 1)
                ->get();

            foreach ($features as $feature) {
                FeaturesBinds::updateOrCreate(
                    [
                        'bot_id' => $botId,
                        'chat_id' => $chatId,
                        'feature_id' => $feature->id,
                    ],
                    [
                        'enabled' => 1,
                        'creator_id' => self::DEFAULT_CREATOR_ID,
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->logMessageService->createLaravelLog(
                self::LOG_FILE,
                [
                    'chat_id' => $chatId,
                    'bot_id' => $botId,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ],
                "绑定功能失败",
                'error'
            );
        }
    }
}
