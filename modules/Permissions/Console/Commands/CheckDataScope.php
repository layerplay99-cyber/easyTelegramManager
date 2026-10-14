<?php

declare(strict_types=1);

namespace Modules\Permissions\Console\Commands;

use Illuminate\Console\Command;
use Modules\Permissions\Models\Departments;
use Modules\Permissions\Models\Jobs;
use Modules\Permissions\Models\Roles;
use Modules\Permissions\Support\DataScope;
use Modules\Permissions\Support\HierarchyScope;
use Modules\User\Models\User;

/**
 * 数据隔离自查命令
 *
 * 用来回答「为什么部门A管理员还能看到/改到别人的数据」：
 * 把一个用户的可见范围完整摊开打印，一眼看出是归属没配对、
 * 还是层级关系（parent_id）把不该看到的东西带了出来。
 *
 * 权限体系数据（角色 / 部门 / 岗位）的可见范围 = creator_id 归属链 + parent_id 子树：
 *   - 自己建的、自己建的账号（递归）建的 → 可见、可改；
 *   - 这些节点的整棵子树 → 可见（子节点是下级建的，上级可改）；
 *   - 上级（含超管）建的 → 不可见，哪怕正被授予 / 分配给本人；
 *   - creator_id = 0 的无主数据 → 同样不可见（权限体系不走
 *     catch.data_scope_include_unowned 的兜底，需要共享就给它指定归属人）。
 *
 * 用法：
 *   php artisan permissions:check-data-scope <用户ID或用户名>
 */
class CheckDataScope extends Command
{
    protected $signature = 'permissions:check-data-scope {user : 用户 ID 或用户名}';

    protected $description = '打印指定用户的数据可见范围（角色/部门/岗位），用于排查部门数据隔离是否生效';

    public function handle(): int
    {
        $keyword = $this->argument('user');

        $user = is_numeric($keyword)
            ? User::query()->find((int) $keyword)
            : User::query()->where('username', $keyword)->first();

        if (! $user) {
            $this->error("找不到用户：{$keyword}");

            return self::FAILURE;
        }

        $scope = app(DataScope::class);
        $hierarchy = app(HierarchyScope::class);

        $this->info("用户 #{$user->id} {$user->username}");
        $this->line('  department_id : ' . var_export($user->department_id, true));
        $this->line('  creator_id    : ' . var_export($user->creator_id, true));
        $this->line('  isSuperAdmin  : ' . var_export($user->isSuperAdmin(), true));

        // ---------- 归属链 ----------
        $chain = $scope->ownershipCreatorIds($user);
        $this->line('');
        $this->info('归属链（creator_id 可见范围）：' . ($chain === null
            ? 'null（不限制，等同超管）'
            : implode(', ', $chain)));

        if ($chain !== null) {
            $subordinates = array_values(array_diff($chain, [(int) $user->id]));

            $this->line('  本人 : #' . $user->id);
            $this->line('  下级 : ' . ($subordinates ? implode(', ', array_map(fn ($id) => '#' . $id, $subordinates)) : '(无)'));
        }

        // ---------- 角色 ----------
        $this->line('');
        $this->info('拥有的角色（被授予，不等于可见）：');

        if ($user->roles->isEmpty()) {
            $this->line('  (无)');
        }

        foreach ($user->roles as $role) {
            $this->line(sprintf(
                '  #%d %s（parent_id=%s, data_range=%s, creator_id=%s）',
                $role->id,
                $role->role_name,
                $role->parent_id,
                $role->data_range,
                $role->creator_id
            ));
        }

        // ---------- 可见范围 ----------
        $visibleRoleIds = $hierarchy->visibleIds(app(Roles::class), $user);
        $visibleDeptIds = $hierarchy->visibleIds(app(Departments::class), $user);

        $this->line('');
        $this->info('可见角色（归属链 + parent_id 子树）：' . $this->formatIds($visibleRoleIds));
        $this->info('可见部门（归属链 + parent_id 子树）：' . $this->formatIds($visibleDeptIds));

        $jobQuery = Jobs::query();
        $scope->applyOwnership($jobQuery, $user, null, false);
        $visibleJobIds = $jobQuery->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->info('可见岗位（归属链）：' . ($visibleJobIds ? implode(', ', $visibleJobIds) : '(无)'));

        // ---------- 实际能查到的数据 ----------
        $this->line('');
        $this->info('实际查询结果：');
        $this->line('  角色  总数 ' . Roles::query()->count() . ' / 可见 ' . $this->countIn(Roles::class, $visibleRoleIds));
        $this->line('  部门  总数 ' . Departments::query()->count() . ' / 可见 ' . $this->countIn(Departments::class, $visibleDeptIds));
        $this->line('  岗位  总数 ' . Jobs::query()->count() . ' / 可见 ' . count($visibleJobIds));

        // ---------- 看到的记录到底是谁的 ----------
        // 这一节是重点：把每条可见记录的归属人摊开，一眼看出能不能改。
        $this->line('');
        $this->info('可见角色归属：');
        foreach ($this->rowsIn(Roles::class, $visibleRoleIds, ['id', 'role_name', 'parent_id', 'creator_id']) as $row) {
            $this->line(sprintf('  #%d %s → %s', $row->id, $row->role_name, $this->ownershipLabel($row, $user)));
        }

        $this->line('');
        $this->info('可见部门归属：');
        foreach ($this->rowsIn(Departments::class, $visibleDeptIds, ['id', 'department_name', 'parent_id', 'creator_id']) as $row) {
            $this->line(sprintf(
                '  #%d %s → %s%s',
                $row->id,
                $row->department_name,
                $this->ownershipLabel($row, $user),
                (int) $row->id === (int) $user->department_id ? '（本人所属部门）' : ''
            ));
        }

        $this->line('');
        $this->info('可见岗位归属：');
        foreach ($this->rowsIn(Jobs::class, $visibleJobIds, ['id', 'job_name', 'creator_id']) as $row) {
            $this->line(sprintf('  #%d %s → %s', $row->id, $row->job_name, $this->ownershipLabel($row, $user)));
        }

        // ---------- 被挡在门外的记录 ----------
        $this->printHidden('角色', Roles::class, $visibleRoleIds, 'role_name');
        $this->printHidden('部门', Departments::class, $visibleDeptIds, 'department_name');
        $this->printHidden('岗位', Jobs::class, $visibleJobIds, 'job_name');

        return self::SUCCESS;
    }

    /**
     * 归属标注：可改 / 只能看 / 无主
     */
    protected function ownershipLabel(mixed $row, mixed $user): string
    {
        $creatorId = (int) $row->creator_id;

        if ($creatorId === 0) {
            return '无主数据（creator_id=0，权限体系内不算可见，需要指定归属人）';
        }

        if ($creatorId === (int) $user->id) {
            return '本人创建（可改）';
        }

        $chain = app(DataScope::class)->ownershipCreatorIds($user);

        if ($chain !== null && in_array($creatorId, $chain, true)) {
            return "下级 #{$creatorId} 创建（可改）";
        }

        return "用户 #{$creatorId} 创建（只能看，不能改）";
    }

    /**
     * 打印被隔离掉的记录，确认上级的数据确实看不到了
     */
    protected function printHidden(string $label, string $model, ?array $visibleIds, string $nameColumn): void
    {
        $query = $model::query()->select(['id', $nameColumn, 'creator_id']);

        if ($visibleIds !== null) {
            $query->whereNotIn('id', $visibleIds ?: [0]);
        }

        $rows = $query->get();

        $this->line('');
        $this->info("不可见{$label}：" . ($rows->isEmpty() ? '（无）' : $rows->count() . ' 条'));

        foreach ($rows as $row) {
            $this->line(sprintf('  #%d %s → creator_id=%s', $row->id, $row->{$nameColumn}, $row->creator_id));
        }
    }

    protected function formatIds(?array $ids): string
    {
        if ($ids === null) {
            return '全部（不限制）';
        }

        return $ids ? implode(', ', $ids) : '(无)';
    }

    protected function countIn(string $model, ?array $ids): int
    {
        $query = $model::query();

        if ($ids !== null) {
            $query->whereIn('id', $ids ?: [0]);
        }

        return $query->count();
    }

    protected function rowsIn(string $model, ?array $ids, array $columns): mixed
    {
        $query = $model::query()->select($columns);

        if ($ids !== null) {
            $query->whereIn('id', $ids ?: [0]);
        }

        return $query->get();
    }
}
