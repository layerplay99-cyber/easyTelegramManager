<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Callback\Transformers;

use Modules\Telegram\Contracts\CallbackMessage;
use Modules\Telegram\Contracts\CallbackTransformer;
use Modules\Telegram\Services\Callback\TransformerContext;

/**
 * 【示例】隐藏关键信息
 *
 * 先按消息模板渲染，再把里面的手机号 / 银行卡 / 邮箱 / 身份证中段替换成 *，
 * 适合支付、开户这类不能明文往群里贴的回调。
 *
 * 这是给开发者抄的骨架：想加自己的处理逻辑，复制一份改 key / label / description
 * 和 mask() 就行，不用动任何配置和数据库。
 */
class MaskedNotifyTransformer implements CallbackTransformer
{
    public static function key(): string
    {
        return 'mask';
    }

    public static function label(): string
    {
        return '隐藏关键信息';
    }

    public static function description(): string
    {
        return '手机号 / 银行卡 / 邮箱 / 身份证中段替换为 *，适合支付、开户类回调';
    }

    public function transform(TransformerContext $context): CallbackMessage
    {
        return CallbackMessage::text(
            $this->mask($context->render()),
            (string) $context->config('parse_mode', '')
        );
    }

    public function mask(string $text): string
    {
        $rules = [
            // 银行卡 / 长数字串：留前 4 后 4
            '/\b(\d{4})\d{8,}(\d{4})\b/' => '$1****$2',
            // 手机号：留前 3 后 4
            '/\b(1[3-9]\d)\d{4}(\d{4})\b/' => '$1****$2',
            // 身份证：留前 4 后 2
            '/\b(\d{4})\d{10,13}(\d{2}[\dXx])\b/' => '$1**********$2',
            // 邮箱：用户名留首尾各 1 位
            '/\b([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*(@[A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/' => '$1***$2',
        ];

        return preg_replace(array_keys($rules), array_values($rules), $text) ?? $text;
    }
}
