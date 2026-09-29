<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Madeline;

use danog\MadelineProto\SimpleEventHandler;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\Update;
use Modules\Telegram\Jobs\CollectEmoji;
use Modules\Telegram\Services\LogMessageService;

class SessionEventHandler extends SimpleEventHandler
{

    /**
     * Handle incoming messages
     * MadelineProto 8.6.0+ uses type-based routing with #[Handler] attribute
     */
    #[Handler]
    public function handleMessage(Message $message): void
    {
        // 获取 chatId
        $chatId = $message->chatId;

        if($chatId && \Illuminate\Support\Facades\Cache::has('telegram_group_collect_emojis_'.$chatId) &&
            isset($message->message)){
            $data = \Illuminate\Support\Facades\Cache::get('telegram_group_collect_emojis_'.$chatId);
            $this->collectEmojis($message, $data);
        }
    }

    public function collectEmojis(Message $message, ?array $data): void
    {
        try {
            if(empty($data['session_file'])){
                app(LogMessageService::class)->createLaravelLog("telegram_error", [
                    'message' => $message->message,
                ],'Collect Emojis Error: session_file is empty');
                return;
            }

            // 将 Message entities 转换为可序列化的数组
            $entities = [];
            foreach ($message->entities as $entity) {
                if ($entity instanceof \danog\MadelineProto\EventHandler\Message\Entities\CustomEmoji) {
                    $entities[] = [
                        'type' => 'custom_emoji',
                        'document_id' => $entity->documentId,
                        'offset' => $entity->offset,
                        'length' => $entity->length,
                    ];
                }
            }

            app(LogMessageService::class)->createLaravelLog("telegramUserSessionEvent", [
                'message' => 'Dispatching CollectEmoji job',
                'custom_emoji_count' => count($entities),
                'session_file' => $data['session_file'],
            ],'Collect Emojis Info');

            if (!empty($entities)) {
                // 带上原文：回退字符（alt）要按 UTF-16 offset 从原文里截出来
                CollectEmoji::dispatch($data['session_file'], $entities, $message->message ?? '');
            } else {
                app(LogMessageService::class)->createLaravelLog("telegramUserSessionEvent", [
                    'message' => 'No custom emojis found in message',
                    'session_file' => $data['session_file'],
                ],'Collect Emojis Info');
            }
        } catch (\Throwable $e) {
            app(LogMessageService::class)->createLaravelLog("telegramUserSessionEvent", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ],'Collect Emojis Error');
        }
    }

    /**
     * Handle incoming updates
     * MadelineProto 8.6.0+ uses type-based routing with #[Handler] attribute
     */
    #[Handler]
    public function handleUpdate(Update $update): void
    {
        try {
            $updateData = $update->jsonSerialize();
            $updateType = $updateData['_'] ?? null;

            app(LogMessageService::class)->createLaravelLog("telegramUserSessionEvent", [
                'message' => 'Received update',
                'update_type' => $updateType,
            ],'Session Update Info');

            if ($updateType === 'updateChannelParticipant' || $updateType === 'updateChatParticipant') {
                app(LogMessageService::class)->createLaravelLog("telegramUserSessionEvent", [
                    'message' => 'Handling participant change',
                    'update_type' => $updateType,
                ],'Session Update Info');
                $this->handleParticipantChange($updateData);
            }
        } catch (\Throwable $e) {
            app(LogMessageService::class)->createLaravelLog("telegramUserSessionEvent", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ],'Session Update Error');
        }
    }

    /**
     * 处理群组成员变化，触发事件
     */
    private function handleParticipantChange(array $updateData): void
    {
        try {
            $chatId = $updateData['channel_id'] ?? $updateData['chat_id'] ?? null;
            $userId = $updateData['user_id'] ?? $updateData['new_participant']['user_id'] ?? null;

            if (!$chatId || !$userId) {
                return;
            }

            $me = $this->getSelf();
            $myUserId = $me['id'] ?? null;

            if ($myUserId != $userId) {
                return;
            }

            $phone = $me['phone'] ?? null;
            if (!$phone) {
                app(LogMessageService::class)->createLaravelLog("telegramUserSessionEvent", [
                    'message' => 'Phone number not found for current user',
                    'user_id' => $userId,
                ],'Session Update Error');
                return;
            }

            $apiUser = \Modules\Telegram\Models\TelegramApiUsers::where(function($query) use ($phone) {
                $query->where('phone_number', $phone)
                      ->orWhere('phone_number', '+' . $phone);
            })->first();

            if (!$apiUser) {
                app(LogMessageService::class)->createLaravelLog("telegramUserSessionEvent", [
                    'message' => 'TelegramApiUser not found for phone number',
                    'phone' => $phone,
                ],'Session Update Error');
                return;
            }

            $newParticipant = $updateData['new_participant'] ?? null;
            $prevParticipant = $updateData['prev_participant'] ?? null;

            $isJoined = $this->isUserJoined($newParticipant, $prevParticipant);
            $isLeft = $this->isUserLeft($newParticipant, $prevParticipant);

            if ($isJoined) {
                app(LogMessageService::class)->createLaravelLog("telegramUserSessionEvent", [
                    'message' => 'User joined group, dispatching event',
                    'app_id' => $apiUser->app_id,
                    'group_id' => $chatId,
                ],'Session Update Info');
                \Modules\Telegram\Events\UserGroupMembershipEvent::dispatch(
                    (string)$apiUser->app_id,
                    (string)$chatId,
                    'joined',
                    $userId,
                    $apiUser->creator_id
                );
            } elseif ($isLeft) {
                app(LogMessageService::class)->createLaravelLog("telegramUserSessionEvent", [
                    'message' => 'User left group, dispatching event',
                    'app_id' => $apiUser->app_id,
                    'group_id' => $chatId,
                ],'Session Update Info');
                \Modules\Telegram\Events\UserGroupMembershipEvent::dispatch(
                    (string)$apiUser->app_id,
                    (string)$chatId,
                    'left',
                    $userId,
                    $apiUser->creator_id
                );
            }
        } catch (\Throwable $e) {
            app(LogMessageService::class)->createLaravelLog("telegramUserSessionEvent", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ],'Session Update Error');
        }
    }

    /**
     * 判断用户是否加入
     */
    private function isUserJoined($newParticipant, $prevParticipant): bool
    {
        if ($newParticipant && !$prevParticipant) {
            return true;
        }

        if (isset($prevParticipant['_']) && $prevParticipant['_'] === 'channelParticipantLeft' &&
            isset($newParticipant['_']) && $newParticipant['_'] !== 'channelParticipantLeft') {
            return true;
        }

        return false;
    }

    /**
     * 判断用户是否离开
     */
    private function isUserLeft($newParticipant, $prevParticipant): bool
    {
        if (!$newParticipant && $prevParticipant) {
            return true;
        }

        if (isset($newParticipant['_']) && $newParticipant['_'] === 'channelParticipantLeft') {
            return true;
        }

        return false;
    }
}

