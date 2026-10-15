<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Callback\Transformers;

use Modules\Telegram\Contracts\CallbackMessage;
use Modules\Telegram\Contracts\CallbackTransformer;
use Modules\Telegram\Services\Callback\TransformerContext;

/**
 * 【示例】带确认按钮的通知
 *
 * 上游回调回来 → 群里发一条带「确认 / 驳回」按钮的消息。
 *
 * 点按钮之后的事不在这里，在另一个「交互按钮点击处理」功能里
 * （执行器 interactive.click，见 InteractiveClickDriver 类注释）：
 *   - 那个功能要绑定到同一个机器人 + 同一个群，否则点了没反应
 *   - 它的 branches 配置的 key 必须和这里的动作码对上（confirm / reject）
 *   - 提交给哪个上游：会话 → 功能绑定 → 机器人，取第一个有值的
 *
 * 这是给开发者抄的骨架：按钮文案和动作码在 BUTTONS 里改，
 * 消息内容在 config 的 reply_template 里配。
 */
class TradeConfirmTransformer implements CallbackTransformer
{
    /**
     * 按钮：text 是显示文案，act 是动作码
     */
    public const BUTTONS = [
        ['text' => '确认', 'act' => 'confirm'],
        ['text' => '驳回', 'act' => 'reject'],
    ];

    public static function key(): string
    {
        return 'trade_confirm';
    }

    public static function label(): string
    {
        return '【示例】带确认按钮';
    }

    public static function description(): string
    {
        return '发模板渲染后的文本，并附带「确认 / 驳回」按钮，点击走交互会话（支付二次确认那套）';
    }

    public function transform(TransformerContext $context): CallbackMessage
    {
        return CallbackMessage::text(
            $context->render(),
            (string) $context->config('parse_mode', '')
        )->withButtons(
            // 会话数据留服务端，callback_data 里只带会话码 + 动作码
            $context->sessionButtons(self::BUTTONS, 'trade')
        );
    }
}
