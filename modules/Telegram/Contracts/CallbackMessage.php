<?php

declare(strict_types=1);

namespace Modules\Telegram\Contracts;

/**
 * 转换器产出的群消息：文本 + 按钮
 *
 * 按钮是一维数组时就排成一行，是二维数组时按行排（每行一个子数组）：
 *   [['text' => '确认', 'callback_data' => 't:abc:confirm']]
 *   [[['text' => '确认', ...]], [['text' => '驳回', ...]]]
 *
 * Telegram 硬限制 callback_data ≤ 64 字节，超长的按钮会被丢弃并记录日志，
 * 免得整条消息因为一个按钮发不出去。
 */
class CallbackMessage
{
    public function __construct(
        public readonly string $text,
        public readonly array $buttons = [],
        public readonly string $parseMode = '',
    ) {
    }

    public static function text(string $text, string $parseMode = ''): self
    {
        return new self($text, [], $parseMode);
    }

    public function withButtons(array $buttons): self
    {
        return new self($this->text, $buttons, $this->parseMode);
    }

    public function withParseMode(string $parseMode): self
    {
        return new self($this->text, $this->buttons, $parseMode);
    }

    public function hasButtons(): bool
    {
        return ! empty($this->buttons);
    }

    /**
     * 转成 sendMessage 参数
     */
    public function toParams(int|string $chatId): array
    {
        $params = ['chat_id' => $chatId, 'text' => $this->text];

        if ($this->parseMode !== '') {
            $params['parse_mode'] = $this->parseMode;
        }

        $keyboard = $this->buildKeyboard();

        if ($keyboard) {
            $params['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        return $params;
    }

    protected function buildKeyboard(): array
    {
        if (! $this->buttons) {
            return [];
        }

        // 一维（一排按钮）自动包一层
        $rows = isset($this->buttons[0]) && isset($this->buttons[0]['text'])
            ? [$this->buttons]
            : $this->buttons;

        $keyboard = [];

        foreach ($rows as $row) {
            $line = [];

            foreach ((array) $row as $button) {
                $text = (string) ($button['text'] ?? '');
                $data = (string) ($button['callback_data'] ?? '');

                if ($text === '' || $data === '') {
                    continue;
                }

                if (strlen($data) > 64) {
                    logger()->warning('回调按钮 callback_data 超过 64 字节，已丢弃', [
                        'text' => $text,
                        'length' => strlen($data),
                    ]);

                    continue;
                }

                $line[] = ['text' => $text, 'callback_data' => $data];
            }

            if ($line) {
                $keyboard[] = $line;
            }
        }

        return $keyboard;
    }
}
