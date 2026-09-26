<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Madeline;

use Modules\Telegram\Events\UserGroupMembershipEvent;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\GroupMembers;
use Modules\Telegram\Models\ServicePeoples;
use Modules\Telegram\Services\LogMessageService;
use Modules\Telegram\Services\Madeline\MadelineService;

class SyncUserGroupService
{
    public function __construct(
        public LogMessageService $logMessageService
    ) {
    }

    /**
     * 同步用户进群
     *
     * @param string $appId  TelegramApiUser 的 app_id
     * @param string $groupId  群 chat_id（负数）
     * @param int $userId  Telegram 用户 ID
     * @param int|null $creatorId  操作人
     */
    public function syncUserJoined(string $appId, string $groupId, int $userId, ?int $creatorId = null): void
    {
        try {
            // 查找 BotGroups 记录（chat_id 匹配）
            $botGroup = BotGroups::where('chat_id', $groupId)
                ->where('app_id', $appId)
                ->first();

            $data = [
                'chat_id' => $groupId,
                'user_id' => $userId,
                'status' => 'member',
                'is_bot' => false,
                'joined_at' => now(),
                'left_at' => null,
                'creator_id' => $creatorId ?? 0,
            ];

            if ($botGroup) {
                $data['group_id'] = $botGroup->id;
            }

            GroupMembers::updateOrCreate(
                [
                    'chat_id' => $groupId,
                    'user_id' => $userId,
                ],
                $data
            );

            $this->logMessageService->createLaravelLog('syncUserJoinLeft', [
                'message' => 'User joined group synced',
                'app_id' => $appId,
                'group_id' => $groupId,
                'user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog('syncUserJoinLeftError', [
                'message' => $e->getMessage(),
                'app_id' => $appId,
                'group_id' => $groupId,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * 同步用户退群
     *
     * @param string $appId  TelegramApiUser 的 app_id
     * @param string $groupId  群 chat_id
     * @param int $userId  Telegram 用户 ID
     */
    public function syncUserLeft(string $appId, string $groupId, int $userId): void
    {
        try {
            GroupMembers::where('chat_id', $groupId)
                ->where('user_id', $userId)
                ->update([
                    'status' => 'left',
                    'left_at' => now(),
                ]);

            $this->logMessageService->createLaravelLog('syncUserJoinLeft', [
                'message' => 'User left group synced',
                'app_id' => $appId,
                'group_id' => $groupId,
                'user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog('syncUserJoinLeftError', [
                'message' => $e->getMessage(),
                'app_id' => $appId,
                'group_id' => $groupId,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * 从真实用户 API 拉取群组并同步到 bot_groups / service_peoples 表
     *
     * 原来的落库逻辑写在 MadelineService::syncForGroups() 里（API 调用与 DB 写混在一起），
     * 这里只负责「落库编排」：从传入的 MadelineService（只读 getGroups()）取数据再写库。
     *
     * @param MadelineService $madelineService  已初始化的真实用户客户端（提供 getGroups()）
     * @param string $appId  TelegramApiUser 的 app_id
     * @param int $loginUserId  操作人
     * @return bool
     */
    public function syncBotGroups(MadelineService $madelineService, string $appId, int $loginUserId): bool
    {
        $allGroups = $madelineService->getGroups();

        if (empty($allGroups)) {
            $this->logMessageService->createLaravelLog(
                'madeline_info',
                ['app_id' => $appId],
                '没有找到任何群组可同步'
            );
            return false;
        }

        \DB::beginTransaction();
        try {
            $groupIds = [];
            $timestamp = now();
            foreach ($allGroups as $group) {
                $botGroup = BotGroups::updateOrCreate(
                    [
                        'app_id' => $appId,
                        'chat_id' => $group['id'],
                    ],
                    [
                        'name' => $group['title'] ?? '未知群组',
                        'title' => $group['title'] ?? '未知群组',
                        'type' => $group['type'],
                        'group_id' => 0,
                        'enabled' => 1,
                        'creator_id' => $loginUserId,
                        'updated_at' => $timestamp,
                    ]
                );

                $groupIds[] = [
                    'group_id' => $botGroup->id,
                    'creator_id' => $loginUserId,
                ];
            }

            foreach ($groupIds as $data) {
                ServicePeoples::updateOrCreate(
                    [
                        'group_id' => $data['group_id'],
                        'app_id' => $appId,
                    ],
                    [
                        'creator_id' => $data['creator_id'],
                    ]
                );
            }

            \DB::commit();
            $this->logMessageService->createLaravelLog(
                'madeline_info',
                ['app_id' => $appId],
                '成功同步 ' . count($allGroups) . ' 个群组'
            );

            return true;
        } catch (\Exception $e) {
            \DB::rollBack();
            $this->logMessageService->createLaravelLog(
                'madeline_error',
                ['app_id' => $appId],
                '同步群组失败: ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * 处理用户群组成员关系变化
     */
    public function handleMembershipChange(UserGroupMembershipEvent $event): void
    {
        if ($event->action === 'joined') {
            $this->syncUserJoined($event->appId, $event->groupId, $event->userId, $event->creatorId);
        } elseif ($event->action === 'left') {
            $this->syncUserLeft($event->appId, $event->groupId, $event->userId);
        }
    }

    /**
     * 主动拉取群成员并同步到 GroupMembers 表
     *
     * @param MadelineService $madelineService  已初始化的 MadelineService 实例
     * @param string $appId  TelegramApiUser 的 app_id
     * @param string $chatId  群 chat_id
     * @param int|null $creatorId  操作人
     * @return int  同步的成员数量
     */
    public function syncGroupMembersFromApi(MadelineService $madelineService, string $appId, string $chatId, ?int $creatorId = null): int
    {
        try {
            $participants = $madelineService->getGroupMembers($chatId);

            if (empty($participants)) {
                $this->logMessageService->createLaravelLog('syncUserJoinLeft', [
                    'message' => 'No members found in group',
                    'app_id' => $appId,
                    'chat_id' => $chatId,
                ]);
                return 0;
            }

            $botGroup = BotGroups::where('chat_id', $chatId)
                ->where('app_id', $appId)
                ->first();

            $count = 0;
            foreach ($participants as $participant) {
                $userId = $participant['user_id'] ?? null;
                if (!$userId) {
                    continue;
                }

                $data = [
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'username' => $participant['username'] ?? null,
                    'status' => 'member',
                    'is_bot' => ($participant['bot'] ?? false) === true,
                    'joined_at' => isset($participant['date']) ? now()->setTimestamp($participant['date']) : now(),
                    'left_at' => null,
                    'creator_id' => $creatorId ?? 0,
                ];

                if ($botGroup) {
                    $data['group_id'] = $botGroup->id;
                }

                GroupMembers::updateOrCreate(
                    [
                        'chat_id' => $chatId,
                        'user_id' => $userId,
                    ],
                    $data
                );
                $count++;
            }

            $this->logMessageService->createLaravelLog('syncUserJoinLeft', [
                'message' => "Synced {$count} members from API",
                'app_id' => $appId,
                'chat_id' => $chatId,
                'count' => $count,
            ]);

            return $count;
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog('syncUserJoinLeftError', [
                'message' => $e->getMessage(),
                'app_id' => $appId,
                'chat_id' => $chatId,
            ]);
            return 0;
        }
    }
}
