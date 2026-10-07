<?php

namespace Modules\Telegram\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\App;
use Modules\Telegram\Events\UserGroupMembershipEvent;
use Modules\Telegram\Services\Madeline\SyncUserGroupService;

// 实现 ShouldQueue，但服务改为在 handle() 内即时解析，而非构造函数注入：
// SyncUserGroupService 含未初始化的强类型属性，构造函数注入会导致监听器序列化入队时
// 因「uninitialized non-nullable property」抛异常，任务进不了队列。延迟解析可干净序列化。
readonly class HandleUserGroupMembershipListener implements ShouldQueue
{
    public function handle(UserGroupMembershipEvent $event): void
    {
        App::make(SyncUserGroupService::class)->handleMembershipChange($event);
    }
}
