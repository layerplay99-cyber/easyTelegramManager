<?php

declare(strict_types=1);

namespace Modules\Telegram\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Telegram\Events\UserGroupMembershipEvent;
use Modules\Telegram\Services\Madeline\SyncUserGroupService;

readonly class HandleUserGroupMembershipListener implements ShouldQueue
{
    public function __construct(
        public SyncUserGroupService $syncUserGroupService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(UserGroupMembershipEvent $event): void
    {
        $this->syncUserGroupService->handleMembershipChange($event);
    }
}
