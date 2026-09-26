<?php

declare(strict_types=1);

namespace Modules\Telegram\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserGroupMembershipEvent
{
    use Dispatchable, SerializesModels;

    public string $appId;
    public string $groupId;
    public string $action; // 'joined' or 'left'
    public int $userId;
    public ?int $creatorId;

    public function __construct(
        string $appId,
        string $groupId,
        string $action,
        int $userId,
        ?int $creatorId = null
    ) {
        $this->appId = $appId;
        $this->groupId = $groupId;
        $this->action = $action;
        $this->userId = $userId;
        $this->creatorId = $creatorId;
    }
}

