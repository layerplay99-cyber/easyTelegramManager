<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

/**
 * 按 update 的内容决定它进哪条队列
 *
 * 为什么拆：queue worker 是串行的。识图单次 1~2s，10 张图并发就是十几秒，
 * 期间 /bd、/ye 这些轻量命令全堵在后面。把图片单独放进 ocr 队列、
 * 起独立的 worker，两边互不阻塞。
 *
 * 规则写在 config('telegram.queues.by_media_type')，按媒体类型路由，
 * 加类型只改配置。解析不出来就走默认队列。
 */
class UpdateQueueResolver
{
    public function __construct(protected UpdateTypeResolver $typeResolver)
    {
    }

    public function queueFor(mixed $update): string
    {
        $default = (string) config('telegram.queues.default', 'default');

        try {
            $intent = $this->typeResolver->resolve($update);
        } catch (\Throwable) {
            return $default;
        }

        $mediaType = strtolower(trim((string) $intent->messageType));

        if ($mediaType === '') {
            return $default;
        }

        $queue = config('telegram.queues.by_media_type.' . $mediaType);

        return is_string($queue) && $queue !== '' ? $queue : $default;
    }
}
