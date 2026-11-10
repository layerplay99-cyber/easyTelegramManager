<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream\Adapters;

/**
 * 直通适配器（基线 / 兜底）
 *
 * 请求原样发、响应原样返回、只看 HTTP 状态判成功 —— 与接插件机制之前的行为
 * 完全一致。三方配置里 adapter 留空、或选中的插件类被删掉时自动用它，
 * 因此老上游一行配置都不用填，升级后行为不变。
 *
 * 它同时也是「插件最小骨架」的参考实现：一个新插件至少要有
 * key / label / description 三个静态方法，其余可以继承基类默认行为。
 */
class DefaultAdapter extends BaseUpstreamAdapter
{
    public static function key(): string
    {
        return 'default';
    }

    public static function label(): string
    {
        return '直通（不适配）';
    }

    public static function description(): string
    {
        return '参数与响应原样透传，仅按 HTTP 状态判断成败。老上游 / 已按平台标准实现的上游用它。';
    }
}
