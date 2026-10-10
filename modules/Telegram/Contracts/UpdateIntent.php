<?php

declare(strict_types=1);

namespace Modules\Telegram\Contracts;

/**
 * Update意图
 *
 * 把 Telegram 各种 update 归一化成结构化意图，让功能侧不用反复判断
 * $update['message']['photo'] 这类底层结构，调用链也更清晰。
 *
 * type 为一级分类（对应 features.trigger），messageType 为二级细分
 * （如 message 下的 text / photo / sticker / voice…）。
 */
class UpdateIntent
{
    /**
     * 一级类型（对应 features.trigger）
     */
    public const TYPE_COMMAND = 'command';
    public const TYPE_MESSAGE = 'message';
    public const TYPE_CALLBACK_QUERY = 'callback_query';
    public const TYPE_INLINE_QUERY = 'inline_query';
    public const TYPE_INLINE_RESULT = 'inline_result';
    public const TYPE_MEMBERSHIP = 'membership';
    public const TYPE_POLL_ANSWER = 'poll_answer';
    public const TYPE_CHAT_JOIN_REQUEST = 'chat_join_request';
    public const TYPE_WEBHOOK = 'webhook';
    public const TYPE_OTHER = 'other';

    /**
     * message 下的媒体细分
     */
    public const MEDIA_TEXT = 'text';
    public const MEDIA_PHOTO = 'photo';
    public const MEDIA_VIDEO = 'video';
    public const MEDIA_AUDIO = 'audio';
    public const MEDIA_VOICE = 'voice';
    public const MEDIA_DOCUMENT = 'document';
    public const MEDIA_STICKER = 'sticker';
    public const MEDIA_ANIMATION = 'animation';
    public const MEDIA_LOCATION = 'location';
    public const MEDIA_CONTACT = 'contact';
    public const MEDIA_POLL = 'poll';
    public const MEDIA_DICE = 'dice';
    public const MEDIA_VENUE = 'venue';
    public const MEDIA_STORY = 'story';

    public function __construct(
        public readonly string $type,
        public readonly ?string $messageType = null,
        public readonly ?int $chatId = null,
        public readonly ?int $userId = null,
        public readonly ?string $text = null,
        public readonly ?string $callbackData = null,
        public readonly mixed $update = null,
        // 按钮点击场景：answerCallbackQuery 要用的 id、被点按钮所在消息的 id
        public readonly ?string $callbackQueryId = null,
        public readonly ?string $messageId = null,
        public readonly ?string $username = null,
    ) {
    }

    /**
     * 是否斜杠命令
     */
    public function isCommand(): bool
    {
        return $this->type === self::TYPE_COMMAND;
    }

    /**
     * 是否按钮点击
     */
    public function isCallbackQuery(): bool
    {
        return $this->type === self::TYPE_CALLBACK_QUERY;
    }

    /**
     * 是否群成员变化事件
     */
    public function isMembership(): bool
    {
        return $this->type === self::TYPE_MEMBERSHIP;
    }

    /**
     * 是否「纯闲聊文本」——没有任何功能的纯文本消息，可以安全过滤
     *
     * 判定：type=message 且 messageType=text 且 文本不以 / 开头。
     * 除此之外的一切（图片、语音、贴纸、按钮、投票…）都不满足此条件，必须放行。
     */
    public function isPlainChat(): bool
    {
        return $this->type === self::TYPE_MESSAGE
            && $this->messageType === self::MEDIA_TEXT
            && ! str_starts_with((string) $this->text, '/');
    }

    /**
     * 是否需要走功能分发（排除闲聊与成员事件）
     */
    public function shouldDispatchFeature(): bool
    {
        return ! $this->isPlainChat() && ! $this->isMembership();
    }

    /**
     * 取命令名（不含斜杠、去掉 @botname）
     */
    public function commandName(): string
    {
        $parts = preg_split('/[\s@]+/', trim((string) $this->text));

        return strtolower(ltrim((string) ($parts[0] ?? ''), '/'));
    }

    /**
     * 取命令位置参数
     *
     * @return array<int, string>
     */
    public function commandArgs(): array
    {
        $parts = preg_split('/\s+/', trim((string) $this->text));
        array_shift($parts);

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * 便于日志排查
     */
    public function describe(): string
    {
        $parts = [sprintf('type=%s', $this->type)];

        if ($this->messageType) {
            $parts[] = sprintf('media=%s', $this->messageType);
        }

        if ($this->chatId !== null) {
            $parts[] = sprintf('chat=%s', $this->chatId);
        }

        return implode(' ', $parts);
    }
}