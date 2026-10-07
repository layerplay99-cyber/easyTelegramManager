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

    public function isSuccess(): bool
    {
        return $this->success;
    }
}