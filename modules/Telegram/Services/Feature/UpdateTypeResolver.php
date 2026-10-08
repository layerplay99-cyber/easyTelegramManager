<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Modules\Telegram\Contracts\UpdateIntent;

/**
 * Update 类型解析器（唯一权威判定处）
 *
 * 以前各类update 的放行规则散落在 Listener 的 if 里（只放行 text/photo/callback_query），
 * 导致语音、贴纸、文件、位置等update 被静默丢弃，功能想用也用不了。
 * 现在把判定集中到这一个类，扩展新类型只改这里。
 *
 * 过滤原则（重要）：
 *   只有「纯文本消息且不是斜杠命令」才允许被过滤；
 *   图片、按钮、语音、贴纸、文件、位置、投票等一切内容都必须放行。
 */
class UpdateTypeResolver
{
    /**
     * message 下的媒体字段 → 意图细分类型
     *
     * 顺序有意义：同一message 只取第一个命中的字段。
     */
    public const MEDIA_MAP = [
        'text' => UpdateIntent::MEDIA_TEXT,
        'photo' => UpdateIntent::MEDIA_PHOTO,
        'video' => UpdateIntent::MEDIA_VIDEO,
        'audio' => UpdateIntent::MEDIA_AUDIO,
        'voice' => UpdateIntent::MEDIA_VOICE,
        'document' => UpdateIntent::MEDIA_DOCUMENT,
        'sticker' => UpdateIntent::MEDIA_STICKER,
        'animation' => UpdateIntent::MEDIA_ANIMATION,
        'location' => UpdateIntent::MEDIA_LOCATION,
        'contact' => UpdateIntent::MEDIA_CONTACT,
        'poll' => UpdateIntent::MEDIA_POLL,
        'dice' => UpdateIntent::MEDIA_DICE,
        'venue' => UpdateIntent::MEDIA_VENUE,
    ];

    /**
     * 解析 update → 意图
     */
    public function resolve(mixed $update): UpdateIntent
    {
        // ---- 群成员变化 ----
        if ($this->isMembership($update)) {
            return new UpdateIntent(
                type: UpdateIntent::TYPE_MEMBERSHIP,
                chatId: $this->extractChatId($update),
                userId: $this->extractUserId($update),
                update: $update,
            );
        }

        // ---- 按钮点击 ----
        if ($this->isType($update, 'callback_query')) {
            return new UpdateIntent(
                type: UpdateIntent::TYPE_CALLBACK_QUERY,
                chatId: $this->extractChatId($update),
                userId: $update->callbackQuery->from->id ?? null,
                callbackData: $update->callbackQuery->data ?? null,
                update: $update,
            );
        }

        // ---- 内联查询 ----
        if ($this->isType($update, 'inline_query')) {
            return new UpdateIntent(
                type: UpdateIntent::TYPE_INLINE_QUERY,
                userId: $update->inlineQuery->from->id ?? null,
                text: $update->inlineQuery->query ?? null,
                update: $update,
            );
        }

        if ($this->isType($update, 'chosen_inline_result')) {
            return new UpdateIntent(
                type: UpdateIntent::TYPE_INLINE_RESULT,
                userId: $update->chosenInlineResult->from->id ?? null,
                callbackData: $update->chosenInlineResult->result_id ?? null,
                update: $update,
            );
        }

        // ---- 投票回调 ----
        if ($this->isType($update, 'poll_answer')) {
            return new UpdateIntent(
                type: UpdateIntent::TYPE_POLL_ANSWER,
                chatId: $update->pollAnswer->voter_chat->id ?? null,
                userId: $update->pollAnswer->user->id ?? null,
                update: $update,
            );
        }

        // ---- 入群申请 ----
        if ($this->isType($update, 'chat_join_request')) {
            return new UpdateIntent(
                type: UpdateIntent::TYPE_CHAT_JOIN_REQUEST,
                chatId: $update->chatJoinRequest->chat->id ?? null,
                userId: $update->chatJoinRequest->from->id ?? null,
                update: $update,
            );
        }

        // ---- 消息类（含全部媒体类型）----
        $message = $this->message($update);

        if ($message !== null) {
            $mediaType = UpdateIntent::MEDIA_TEXT;

            foreach (self::MEDIA_MAP as $field => $intentType) {
                if ($this->has($message, $field)) {
                    $mediaType = $intentType;
                    break;
                }
            }

            $text = $message->text ?? null;

            // 文本且以 / 开头 → 升级为 command，交由命令分发链路
            $isCommand = $mediaType === UpdateIntent::MEDIA_TEXT
                && is_string($text)
                && str_starts_with($text, '/');

            return new UpdateIntent(
                type: $isCommand ? UpdateIntent::TYPE_COMMAND : UpdateIntent::TYPE_MESSAGE,
                messageType: $mediaType,
                chatId: $message->chat->id ?? null,
                userId: $message->from->id ?? null,
                text: $text,
                update: $update,
            );
        }

        // ---- 其它 update（edited_message、pinned_message 等）----
        return new UpdateIntent(
            type: UpdateIntent::TYPE_OTHER,
            chatId: $this->extractChatId($update),
            userId: $this->extractUserId($update),
            update: $update,
        );
    }

    /**
     * 是否群成员变化事件
     */
    public function isMembership(mixed $update): bool
    {
        return $this->has($update, 'my_chat_member')
            || $this->has($update, 'chat_member')
            || isset($update?->message?->new_chat_members)
            || isset($update?->message?->left_chat_member);
    }

    /**
     * 取 message 对象（同时兼容 message / edited_message）
     */
    private function message(mixed $update): ?object
    {
        foreach (['message', 'edited_message', 'channel_post'] as $key) {
            $message = $update->{$key} ?? null;

            if ($message !== null && isset($message->chat)) {
                return $message;
            }
        }

        return null;
    }

    /**
     * 兼容 telegram-bot-sdk 的 isType() 与原生数组访问
     */
    private function isType(mixed $update, string $type): bool
    {
        if (is_object($update) && method_exists($update, 'isType')) {
            return (bool) $update->isType($type);
        }

        return $this->has($update, $type);
    }

    /**
     * 安全判断字段是否存在（对象/数组都支持）
     */
    private function has(mixed $target, string $key): bool
    {
        if (is_array($target)) {
            return isset($target[$key]);
        }

        if (is_object($target)) {
            return isset($target->{$key});
        }

        return false;
    }

    /**
     * 提取 chat_id
     */
    public function extractChatId(mixed $update): ?int
    {
        $candidates = [
            $update->message->chat->id ?? null,
            $update->edited_message->chat->id ?? null,
            $update->channel_post->chat->id ?? null,
            $update->callbackQuery->message->chat->id ?? null,
            $update->my_chat_member->chat->id ?? null,
            $update->chat_member->chat->id ?? null,
            $update->chat_join_request->chat->id ?? null,
        ];

        foreach ($candidates as $id) {
            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * 提取user_id
     */
    public function extractUserId(mixed $update): ?int
    {
        $candidates = [
            $update->message->from->id ?? null,
            $update->edited_message->from->id ?? null,
            $update->channel_post->from->id ?? null,
            $update->callbackQuery->from->id ?? null,
            $update->inlineQuery->from->id ?? null,
            $update->my_chat_member->from->id ?? null,
            $update->chat_member->from->id ?? null,
        ];

        foreach ($candidates as $id) {
            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }
}