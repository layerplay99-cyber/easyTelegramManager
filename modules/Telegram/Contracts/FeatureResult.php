<?php

declare(strict_types=1);

namespace Modules\Telegram\Contracts;

/**
 * 功能执行结果
 *
 * 执行与发送解耦：Driver 只负责产出结果，由统一管线决定怎么发。
 * 这样同一个 Driver 既能被斜杠命令调用，也能被 webhook 调用。
 */
class FeatureResult
{
    private function __construct(
        public readonly bool $success,
        public readonly string $message = '',
        public readonly array $data = [],
        public readonly array $attachments = [],
        public readonly array $meta = [],
    ) {
    }

    /**
     * 成功并带一条回复文本
     */
    public static function reply(string $message, array $data = [], array $meta = []): self
    {
        return new self(true, $message, $data, [], $meta);
    }

    /**
     * 成功但无需回复（如仅写库）
     */
    public static function ok(array $data = [], array $meta = []): self
    {
        return new self(true, '', $data, [], $meta);
    }

    /**
     * 失败
     */
    public static function fail(string $message, array $meta = []): self
    {
        return new self(false, $message, [], [], $meta);
    }

    /**
     * 带内联按钮的回复
     *
     * reply_markup 走 meta，由统一发送段透传给 sendMessage，
     * Driver 自己不需要（也不应该）直接调 telegram 发消息。
     *
     * @param array<string, mixed> $replyMarkup 如 ['inline_keyboard' => [[...]]]
     */
    public static function withKeyboard(string $message, array $replyMarkup, array $data = [], array $meta = []): self
    {
        $meta['reply_markup'] = $replyMarkup;

        return new self(true, $message, $data, [], $meta);
    }

    /**
     * 追加/覆盖 meta（按钮应答、编辑原消息等指令）
     */
    public function withMeta(array $meta): self
    {
        return new self($this->success, $this->message, $this->data, $this->attachments, array_merge($this->meta, $meta));
    }

    /**
     * 按钮应答（answerCallbackQuery）：不点这个按钮会一直转圈
     */
    public function answerCallback(string $text = '', bool $showAlert = false): self
    {
        return $this->withMeta(['answer_callback' => ['text' => $text, 'show_alert' => $showAlert]]);
    }

    /**
     * 编辑触发消息（二次确认、处理完撤按钮等）
     *
     * @param array<string, mixed> $replyMarkup 传 ['inline_keyboard' => []] 表示清空按钮
     */
    public function editMessage(?string $text = null, ?array $replyMarkup = null, ?string $messageId = null): self
    {
        $edit = array_filter(
            ['text' => $text, 'reply_markup' => $replyMarkup, 'message_id' => $messageId],
            fn ($v) => $v !== null
        );

        return $this->withMeta(['edit_message' => $edit]);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }
}