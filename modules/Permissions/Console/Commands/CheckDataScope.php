<?php

declare(strict_types=1);

namespace Modules\Permissions\Console\Commands;

use Illuminate\Console\Command;
use Modules\Permissions\Models\Departments;
use Modules\Permissions\Models\Jobs;
use Modules\Permissions\Models\Roles;
use Modules\Permissions\Support\DataScope;
use Modules\User\Models\User;

/**
 * 数据隔离自查命令
 *
 * 用来回答「为什么部门A管理员还能看到/改到别人的数据」：
 * 把一个用户的可见范围完整摊开打印，一眼能看出是数据没配对（比如他的角色
 * 是超管角色的父级）还是代码没生效。
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

        $this->info("用户 #{$user->id} {$user->username}");
        $this->line('  department_id : ' . var_export($user->department_id, true));
        $this->line('  creator_id    : ' . var_export($user->creator_id, true));
        $this->line('  isSuperAdmin  : ' . var_export($user->isSuperAdmin(), true));

        // ---------- 角色 ----------
        $roleIds = $user->roles->pluck('id')->all();
        $this->line('');
        $this->info('拥有的角色：' . ($roleIds ? implode(', ', $roleIds) : '(无)'));

        foreach ($user->roles as $role) {
            $this->line(sprintf(
                '  #%d %s（parent_id=%s, data_range=%s）',
                $role->id,
                $role->role_name,
                $role->parent_id,
                $role->data_range
            ));
        }

        if ($user->isSuperAdmin()) {
            $descendantRoleIds = Roles::query()->pluck('id')->map(fn ($i) => (int) $i)->all();
            $this->info('可见角色：全部（超级管理员不限制）');
        } else {
            $descendantRoleIds = array_map('intval', array_unique(array_merge(
                (new Roles)->getDescendantRoleIds($roleIds),
                (new Roles)->where('creator_id', $user->id)->pluck('id')->all()
            )));
            $this->info('可见角色（本人角色 + 全部子角色 + 本人创建的）：' . ($descendantRoleIds ? implode(', ', $descendantRoleIds) : '(无)'));
        }

        // 反查：这些「不可见」的角色里，有没有父级落在可见范围内
        $hiddenRoles = Roles::query()->whereNotIn('id', $descendantRoleIds)->get(['id', 'parent_id', 'role_name']);
        if ($hiddenRoles->isNotEmpty()) {
            $leak = $hiddenRoles->filter(fn ($r) => in_array((int) $r->parent_id, array_map('intval', $descendantRoleIds), true));
            if ($leak->isNotEmpty()) {
                $this->warn('  ⚠ 以下角色的父级在你可见范围内，它们会作为子角色一并显示：');
                foreach ($leak as $r) {
                    $this->line(sprintf('    #%d %s（parent_id=%s）', $r->id, $r->role_name, $r->parent_id));
                }
            }
        }

        // ---------- 部门 ----------
        if ($user->isSuperAdmin()) {
            $visibleDeptIds = Departments::query()->pluck('id')->map(fn ($i) => (int) $i)->all();
            $this->info('可见部门：全部（超级管理员不限制）');
        } else {
            $departmentId = (int) $user->department_id;
            $followIds = $departmentId ? (new Departments)->findFollowDepartments($departmentId) : [];
            $visibleDeptIds = array_map('intval', array_unique(array_merge(
                $departmentId ? array_merge($followIds, [$departmentId]) : [],
                (new Departments)->where('creator_id', $user->id)->pluck('id')->all()
            )));
            $this->info('可见部门（本部门 + 全部子部门 + 本人创建的）：' . ($visibleDeptIds ? implode(', ', $visibleDeptIds) : '(无，department_id 为 0)'));
        }

        // ---------- DataScope ----------
        $scope = app(DataScope::class);
        $visibleCreatorIds = $scope->visibleCreatorIds($user);
        $this->line('');
        $this->info('DataScope 可见 creator_id：' . ($visibleCreatorIds === null ? 'null（不限制，等同超管）' : implode(', ', $visibleCreatorIds)));

        // ---------- 实际能查到的数据 ----------
        $this->line('');
        $this->info('实际查询结果：');
        $this->line('  角色  总数 ' . Roles::query()->count() . ' / 可见 ' . Roles::query()->whereIn('id', $descendantRoleIds)->count());
        $this->line('  部门  总数 ' . Departments::query()->count() . ' / 可见 ' . ($visibleDeptIds ? Departments::query()->whereIn('id', $visibleDeptIds)->count() : 0));
        $this->line('  岗位  总数 ' . Jobs::query()->count() . ' / 按 creator_id 可见 ' . ($visibleCreatorIds === null ? Jobs::query()->count() : Jobs::query()->whereIn('creator_id', $visibleCreatorIds)->count()));

        // ---------- 看到的记录到底是谁的 ----------
        // 这一节是重点：「列表里有数据」≠ 「看到了别人的数据」。
        // 把每条可见记录的归属人摊开，一眼看出是不是本人创建的。
        $this->line('');
        $this->info('可见角色归属：');
        foreach (Roles::query()->whereIn('id', $descendantRoleIds)->get(['id', 'role_name', 'parent_id', 'creator_id']) as $r) {
            $this->line(sprintf(
                '  #%d %s → %s',
                $r->id,
                $r->role_name,
                (int) $r->creator_id === (int) $user->id ? '本人创建（可改）' : ((int) $r->creator_id === 0 ? '无主数据' : "用户 #{$r->creator_id} 创建（只能看，不能改）")
            ));
        }

        $this->line('');
        $this->info('可见部门归属：');
        foreach (Departments::query()->whereIn('id', $visibleDeptIds ?: [0])->get(['id', 'department_name', 'parent_id', 'creator_id']) as $d) {
            $this->line(sprintf(
                '  #%d %s → %s%s',
                $d->id,
                $d->department_name,
                (int) $d->creator_id === (int) $user->id ? '本人创建（可改）' : ((int) $d->creator_id === 0 ? '无主数据' : "用户 #{$d->creator_id} 创建（只能看，不能改）"),
                (int) $d->id === (int) $user->department_id ? '（本人所属部门）' : ''
            ));
        }

        // ---------- 岗位归属分布 ----------
        $this->line('');
        $this->info('岗位 creator_id 分布：');
        foreach (Jobs::query()->selectRaw('creator_id, count(*) as c')->groupBy('creator_id')->get() as $row) {
            $this->line(sprintf('  creator_id=%s → %d 个', $row->creator_id, $row->c));
        }

        // ---------- 角色归属分布 ----------
        $this->line('');
        $this->info('角色 creator_id / parent_id 分布：');
        foreach (Roles::query()->selectRaw('creator_id, parent_id, count(*) as c')->groupBy('creator_id', 'parent_id')->get() as $row) {
            $this->line(sprintf('  creator_id=%s parent_id=%s → %d 个', $row->creator_id, $row->parent_id, $row->c));
        }

        // ---------- 部门归属分布 ----------
        $this->line('');
        $this->info('部门 creator_id / parent_id 分布：');
        foreach (Departments::query()->selectRaw('creator_id, parent_id, count(*) as c')->groupBy('creator_id', 'parent_id')->get() as $row) {
            $this->line(sprintf('  creator_id=%s parent_id=%s → %d 个', $row->creator_id, $row->parent_id, $row->c));
        }

        return self::SUCCESS;
    }
}
