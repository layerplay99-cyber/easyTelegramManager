<?php

declare(strict_types=1);

namespace Modules\Telegram\Services;

use Modules\Telegram\Models\GroupConfigs;
use Modules\Telegram\Services\Translate\ReplyTranslator;
use Telegram\Bot\Api;

/**
 * 纯文本消息处理（不属于任何命令的普通聊天内容）
 *
 * UpdateRouter 对「纯文本且非命令」默认直接丢弃，这里插入两个群级开关：
 *
 *   1. 自动翻译（group_configs.translate_enabled，/fy start|stop 或后台开关）：
 *      群内任何文字内容翻译成该群绑定的语种后原样回发。
 *
 *   2. 客服关键词（group_configs.service_keywords + customers）：
 *      文本命中关键词时，@ 群配置里的客服人员并附上原话。
 *
 * 两个开关互不影响：都关 → 维持旧行为（丢弃）；都开 → 先做客服命中，
 * 没命中再翻译（客服提醒优先，避免关键词消息被译文冲掉）。
 */
class PlainChatHandler
{
    public function __construct(
        protected ReplyTranslator $translator,
        protected LogMessageService $logMessageService,
    ) {
    }

    public function handle(Api $telegram, int|string $chatId, string $text, ?string $username = null): void
    {
        $config = GroupConfigs::forChat($chatId);

        if (! $config) {
            return;
        }

        // ---- 1) 客服关键词 ----
        if ($this->handleServiceKeyword($telegram, $chatId, $text, $username, $config)) {
            return;
        }

        // ---- 2) 自动翻译 ----
        if ((bool) $config->translate_enabled) {
            $this->translateBack($telegram, $chatId, $text, (string) $config->botGroup?->language());
        }
    }

    /**
     * 关键词命中 → @ 群配置的客服
     */
    protected function handleServiceKeyword(
        Api $telegram,
        int|string $chatId,
        string $text,
        ?string $username,
        GroupConfigs $config
    ): bool {
        $keywords = is_array($config->service_keywords) ? $config->service_keywords : [];
        $keywords = array_values(array_filter(array_map('trim', $keywords)));

        $customers = is_array($config->customers) ? $config->customers : [];

        if ($keywords === [] || $customers === []) {
            return false;
        }

        $hit = false;

        foreach ($keywords as $keyword) {
            if ($keyword !== '' && mb_stripos($text, $keyword) !== false) {
                $hit = true;
                break;
            }
        }

        if (! $hit) {
            return false;
        }

        // @ 客服：有 username 用 @username，否则用 tg://user?id= 的文本提及
        $mentions = [];

        foreach ($customers as $customer) {
            $name = trim((string) ($customer['username'] ?? ''));
            $id = (int) ($customer['id'] ?? 0);

            if ($name !== '') {
                $mentions[] = '@' . ltrim($name, '@');
            } elseif ($id > 0) {
                $mentions[] = "<a href=\"tg://user?id={$id}\">客服</a>";
            }
        }

        if ($mentions === []) {
            return false;
        }

        $lines = [implode(' ', array_unique($mentions)), ''];
        $lines[] = $username !== null ? "@{$username} 的消息需要客服处理：" : '以下消息需要客服处理：';
        $lines[] = $text;

        try {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => implode("\n", $lines),
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog('plain_chat', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ], '客服提醒发送失败', 'warning');
        }

        return true;
    }

    /**
     * 翻译成群语种后回发
     */
    protected function translateBack(Api $telegram, int|string $chatId, string $text, string $language): void
    {
        try {
            $translated = $this->translator->translateTo($text, $language);

            // 翻译失败会原样返回，发回原文没有意义，直接跳过
            if ($translated === $text) {
                return;
            }

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $translated,
            ]);
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog('plain_chat', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ], '群消息翻译失败', 'warning');
        }
    }
}
