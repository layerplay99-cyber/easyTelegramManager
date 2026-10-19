<?php

declare(strict_types=1);

namespace Modules\Telegram\Services;

use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\GroupAdmins;
use Modules\Telegram\Models\GroupMembers;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Modules\Telegram\Services\Feature\FeatureDataStore;

/**
 * 「谁能点这个按钮」的策略
 *
 * 旧实现只有「管理员才能点」一种写死的判断，且判定松散。这里做成可配置：
 *   /trsq @a 123456   → 仅这些人可点（白名单）
 *   /trsq clear       → 清空，回到默认（仅群管理员）
 *   从没设置过         → 默认仅群管理员
 *
 * 存储：复用 feature_data，feature_id 固定为 0 —— 约定为「群级通用配置桶」，
 * scope_type=group / scope_id=chat_id。不为此再建一张表，读写直接走 FeatureDataStore
 * （自带缓存，和别的功能数据同一套机制）。
 */
class OperatorPolicy
{
    /**
     * feature_data 里 feature_id=0 保留给「群级通用配置」
     */
    public const GROUP_SETTING_FEATURE_ID = 0;

    /**
     * 算「管理员」的 Telegram 身份
     *
     * creator = 群主，administrator = 管理员，两者都要放行。
     */
    public const ADMIN_STATUSES = ['creator', 'administrator'];

    public function __construct(protected FeatureDataStore $store)
    {
    }

    /**
     * 读取当前群的操作人配置
     *
     * @return array{mode:string,user_ids:array<int,int>,usernames:array<int,string>}
     */
    public function settings(string $chatId): array
    {
        $raw = $this->store->get(self::GROUP_SETTING_FEATURE_ID, 'group', (string) $chatId);

        $ids = [];
        foreach ((array) ($raw['click_users'] ?? []) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $names = [];
        foreach ((array) ($raw['click_names'] ?? []) as $name) {
            $name = ltrim(trim((string) $name), '@');
            if ($name !== '') {
                $names[] = strtolower($name);
            }
        }

        $mode = (string) ($raw['click_policy'] ?? '');

        return [
            'mode' => $mode === 'whitelist' ? 'whitelist' : 'admin',
            'user_ids' => array_values(array_unique($ids)),
            'usernames' => array_values(array_unique($names)),
        ];
    }

    /**
     * 写入操作人配置
     *
     * @param array<int, int>    $userIds
     * @param array<int, string> $usernames
     */
    public function save(string $chatId, array $userIds, array $usernames, string $mode = 'whitelist'): void
    {
        $this->store->put(self::GROUP_SETTING_FEATURE_ID, 'group', (string) $chatId, [
            'click_policy' => $mode,
            'click_users' => array_values(array_filter(array_map('intval', $userIds))),
            'click_names' => array_values(array_filter(array_map(
                fn ($n) => ltrim(trim((string) $n), '@'),
                $usernames
            ))),
        ]);
    }

    /**
     * 清空（回到默认：仅管理员）
     */
    public function clear(string $chatId): void
    {
        $this->store->put(self::GROUP_SETTING_FEATURE_ID, 'group', (string) $chatId, [
            'click_policy' => 'admin',
            'click_users' => [],
            'click_names' => [],
        ]);
    }

    /**
     * 是否允许该用户点击
     */
    public function allows(array $settings, string $chatId, ?int $userId, ?string $username = null, ?Bots $bot = null): bool
    {
        if (! $userId) {
            return false;
        }

        if (($settings['mode'] ?? 'admin') === 'whitelist') {
            if (in_array((int) $userId, $settings['user_ids'] ?? [], true)) {
                return true;
            }

            $username = strtolower(ltrim(trim((string) $username), '@'));

            if ($username !== '' && in_array($username, $settings['usernames'] ?? [], true)) {
                return true;
            }

            // 白名单模式下管理员也放行，避免设了名单把管理员锁在外面
            return $this->isGroupAdmin($chatId, $userId, $bot);
        }

        // 默认：仅群管理员
        return $this->isGroupAdmin($chatId, $userId, $bot);
    }

    /**
     * 是否群管理员（含群主）
     *
     * 有 bot 就只问官方：getChatMember(chat_id, user_id) 返回这个人在该群的身份，
     * creator = 群主、administrator = 管理员，两者都放行。命令是谁发的，$userId 就是谁，
     * 一条命令一次调用即可，不需要任何本地快照。
     *
     * group_admins 只在「连官方都问不出来、且手上没有可调用的 bot」时才看一眼，
     * 平时不参与判定 —— 它只是 syncChatAdmins() 在机器人入群那一瞬间拉的快照，
     * 之后有人被提升/撤销都不知道，拿它当权威就会出现「群主也不是管理员」。
     */
    public function isGroupAdmin(string $chatId, ?int $userId, ?Bots $bot = null): bool
    {
        if (! $userId) {
            return false;
        }

        if ($bot) {
            return $this->isGroupAdminLive($bot, (string) $chatId, (int) $userId) ?? false;
        }

        // 没有 bot 可用（问不了官方）才走这一步
        return $this->isAdminInDb((string) $chatId, (int) $userId);
    }

    /**
     * 问官方：这个人在该群里是不是 creator / administrator
     *
     * @return bool|null null = 问不出来（接口报错 / 返回里没有 status）
     */
    protected function isGroupAdminLive(Bots $bot, string $chatId, int $userId): ?bool
    {
        try {
            $member = app(BotApiFactory::class)->forBot($bot)->getChatMember([
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);

            $status = is_array($member)
                ? (string) ($member['status'] ?? '')
                : (string) ($member->status ?? '');

            $status = strtolower($status);

            if ($status === '') {
                return null;
            }

            $isAdmin = in_array($status, self::ADMIN_STATUSES, true);

            // 顺手回填 group_admins：判定不读它，但后台「群管理员」列表要看这份数据
            if ($isAdmin) {
                $this->rememberAdmin($chatId, $userId, $status, $member);
            }

            return $isAdmin;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 官方判定结果的备份（写失败不影响本次判定）
     */
    protected function rememberAdmin(string $chatId, int $userId, string $status, mixed $member): void
    {
        try {
            $user = is_array($member) ? ($member['user'] ?? []) : ($member->user ?? null);

            GroupAdmins::updateOrCreate(
                ['chat_id' => $chatId, 'user_id' => $userId],
                [
                    'status' => $status,
                    'username' => (string) (is_array($user) ? ($user['username'] ?? '') : ($user->username ?? '')),
                ]
            );
        } catch (\Throwable) {
            // 备份写不进去无所谓，下次还能问官方
        }
    }

    /**
     * 备份判定：group_admins 里有没有这个人
     */
    protected function isAdminInDb(string $chatId, int $userId): bool
    {
        return GroupAdmins::query()
            ->where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->whereIn('status', self::ADMIN_STATUSES)
            ->exists();
    }

    /**
     * 把 /trsq 后面写的 @用户名 解析成 telegram user id（查本群成员）
     *
     * 用户名随时可改，能用 id 就用 id；这里只是给「只记得用户名」的场景兜底。
     *
     * @return array{ids:array<int,int>,names:array<int,string>,unknown:array<int,string>}
     */
    public function parseTargets(string $chatId, array $tokens): array
    {
        $ids = [];
        $names = [];
        $unknown = [];

        foreach ($tokens as $token) {
            $token = trim((string) $token);

            if ($token === '') {
                continue;
            }

            if (preg_match('/^\d+$/', $token)) {
                $ids[] = (int) $token;

                continue;
            }

            $name = strtolower(ltrim($token, '@'));

            $member = GroupMembers::query()
                ->where('chat_id', (string) $chatId)
                ->where('username', $name)
                ->value('user_id');

            if ($member) {
                $ids[] = (int) $member;

                continue;
            }

            // 群里查不到就按用户名匹配（点击时比对 from.username）
            $names[] = $name;
            $unknown[] = $name;
        }

        return [
            'ids' => array_values(array_unique($ids)),
            'names' => array_values(array_unique($names)),
            'unknown' => array_values(array_unique($unknown)),
        ];
    }
}
