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
     * ⚠️ 注意语义：这里是「按 category 全量绑」，**不按 bot_id 过滤**，
     *    会把系统里所有 enabled=1 的该类功能都写成 enabled=1。
     *
     *    所以它只适用于「给一个实体建立初始白名单」的场景
     *    （例如新建真人账号时绑 realMan 类功能，见 TelegramApiUserController::store）。
     *    **不要**拿它给群自动绑功能 —— 那等于新群自动全选，而且绑的是
     *    「系统里所有功能」而不是「这个机器人被分配的功能」，与
     *    「机器人白名单 > 群子集」冲突。机器人入群曾这么干过，已移除，别再加回来。
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
