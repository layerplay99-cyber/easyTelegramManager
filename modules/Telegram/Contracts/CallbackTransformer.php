<?php

declare(strict_types=1);

namespace Modules\Telegram\Contracts;

use Modules\Telegram\Services\Callback\TransformerContext;

/**
 * 回调消息转换器
 *
 * 只负责一件事：把上游回调报文变成「要发到群里的消息（文本 + 按钮）」。
 * 验签、路由（商户ID → 机器人 + 群）、发送都不归它管——
 * 「用哪个机器人发、发到哪个群」必须由系统决定，不能交给业务代码，
 * 否则又会出现拿 A 的机器人发到 B 的群这种串线。
 *
 * 接入方式：往 config/transformers.php 配的目录里丢一个实现类即可，
 * 后台下拉自动出现，不用注册、不用改数据库。
 */
interface CallbackTransformer
{
    /**
     * 稳定标识，存进 features.config.transformer / features_binds.config.transformer
     */
    public static function key(): string;

    /**
     * 后台下拉里显示的名称
     */
    public static function label(): string;

    /**
     * 后台下拉里显示的描述，说明这个转换器是干什么的
     */
    public static function description(): string;

    /**
     * 报文 → 群消息
     */
    public function transform(TransformerContext $context): CallbackMessage;
}
