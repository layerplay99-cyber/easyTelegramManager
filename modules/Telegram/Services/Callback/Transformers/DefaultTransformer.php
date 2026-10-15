<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Callback\Transformers;

use Modules\Telegram\Contracts\CallbackMessage;
use Modules\Telegram\Contracts\CallbackTransformer;
use Modules\Telegram\Services\Callback\TransformerContext;

/**
 * 默认转换器：按功能配置里的「消息模板」渲染
 *
 * 这就是原来的行为（reply_template + {{字段}}），
 * 所有没选转换器的功能都走这里，升级后行为完全不变。
 */
class DefaultTransformer implements CallbackTransformer
{
    public static function key(): string
    {
        return 'default';
    }

    public static function label(): string
    {
        return '默认';
    }

    public static function description(): string
    {
        return '按功能配置里的「消息模板」渲染，{{字段名}} 取回调字段，{{_raw}} 为整段原文';
    }

    public function transform(TransformerContext $context): CallbackMessage
    {
        return CallbackMessage::text(
            $context->render(),
            (string) $context->config('parse_mode', '')
        );
    }
}
