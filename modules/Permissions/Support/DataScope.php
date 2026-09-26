<?php
declare(strict_types=1);

namespace Modules\Permissions\Support;

use Catch\Exceptions\FailedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\Permissions\Enums\DataRange as DataRangeEnum;
use Modules\Permissions\Models\Departments;

/**
 * 后台数据可见范围
 *
 * 规则（按需求）：
 *  1. 超级管理员：全部数据。
 *  2. 普通用户：本人 + 所有下级用户（users.creator_id 递归）的数据始终可见，
 *     不受数据权限收窄影响。
 *  3. 叠加角色数据权限 roles.data_range：
 *     1 全部数据 / 2 自定义部门 / 3 仅本人 / 4 本部门 / 5 本部门及以下；
 *     任一角色为「全部数据」即放开全部。
 *  4. 模块维度：模型声明了 $dataModule 时，当前用户必须拥有该模块的功能权限，
 *     否则该模块的数据完全不可见（未声明该属性的模型不做模块级限制）。
 *
 * 说明：本服务只在有登录用户时生效；命令行 / 队列 / 免登录接口不做任何过滤，
 * 避免在后台任务里把数据过滤掉。
 */
class DataScope
{
    /**
     * 单次请求内的缓存
     */
    protected static array $cache = [];

    /**
     * 当前登录用户
     */
    public function user(): mixed
    {
        return Auth::guard(getGuardName())->user();
    }

    /**
     * 可见数据的 creator_id 集合
     *
     * @return array|null null 表示不限制
     */
    public function visibleCreatorIds(mixed $user = null): ?array
    {
        $user = $user ?: $this->user();

        // 无登录上下文（命令行 / 队列 / 免登录接口）不限制
        if (! $user) {
            return null;
        }

        // 超管看全部
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return null;
        }

        $cacheKey = 'scope_'.$user->id;

        if (array_key_exists($cacheKey, static::$cache)) {
            return static::$cache[$cacheKey];
        }

        // 本人 + 下级：始终可见
        $userIds = Collection::make($this->subordinateIds($user))->push($user->id);

        foreach ($this->rolesOf($user) as $role) {
            // 任一角色是「全部数据」直接放开
            if (DataRangeEnum::All_Data->assert((int) $role->data_range)) {
                return static::$cache[$cacheKey] = null;
            }

            $userIds = $userIds->merge($this->userIdsByDataRange($role, $user));
        }

        return static::$cache[$cacheKey] = $userIds
            ->filter(fn ($id) => ! empty($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 下级用户 ID（按 users.creator_id 递归，不含本人）
     */
    public function subordinateIds(mixed $user): array
    {
        $userId = (int) (is_object($user) ? $user->id : $user);

        $cacheKey = 'subordinate_'.$userId;

        if (isset(static::$cache[$cacheKey])) {
            return static::$cache[$cacheKey];
        }

        $userModel = app(getAuthUserModel());

        $ids = [];
        $level = [$userId];

        while (! empty($level)) {
            $next = $userModel->newQuery()->whereIn('creator_id', $level)->pluck('id')
                ->map(fn ($id) => (int) $id)->all();

            // 去掉已收录的，防止数据成环时死循环
            $next = array_values(array_diff($next, $ids, [$userId]));

            if (empty($next)) {
                break;
            }

            $ids = array_merge($ids, $next);
            $level = $next;
        }

        return static::$cache[$cacheKey] = array_values(array_unique($ids));
    }

    /**
     * 单条记录是否在当前用户可见范围内
     */
    public function isVisible(Model $model, mixed $user = null): bool
    {
        $user = $user ?: $this->user();

        if (! $user) {
            return true;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        $userIds = $this->visibleCreatorIds($user);

        if ($userIds === null) {
            return true;
        }

        $creatorId = $model->getAttribute('creator_id');

        // 无主数据（creator_id 为空 / 0）是否可见由配置决定
        if (empty($creatorId)) {
            return (bool) config('catch.data_scope_include_unowned', true);
        }

        return in_array((int) $creatorId, $userIds, true);
    }

    /**
     * 不在可见范围内直接抛异常，用于 show / update / destroy 的越权拦截
     *
     * @throws FailedException
     */
    public function assertVisible(Model $model, mixed $user = null): void
    {
        if (! $this->isVisible($model, $user)) {
            throw new FailedException('无权访问该数据');
        }
    }

    /**
     * 当前用户是否拥有指定模块的功能权限（未授权模块的数据不可见）
     */
    public function hasModuleAccess(string $module, mixed $user = null): bool
    {
        $user = $user ?: $this->user();

        if (! $user) {
            return true;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        $cacheKey = 'modules_'.$user->id;

        if (! array_key_exists($cacheKey, static::$cache)) {
            static::$cache[$cacheKey] = $this->resolveModules($user);
        }

        $modules = static::$cache[$cacheKey];

        // 权限模块未启用 / 取不到权限数据时不做模块级限制
        if ($modules === null) {
            return true;
        }

        return in_array($module, $modules, true);
    }

    /**
     * 给查询构造器套上数据范围
     *
     * @param mixed $query Eloquent Builder
     * @param string|null $module 模型所属模块，非空时校验模块权限
     * @param string|null $field 归属字段，默认 creator_id
     */
    public function apply(mixed $query, mixed $user = null, ?string $module = null, ?string $field = null): mixed
    {
        $user = $user ?: $this->user();

        if (! $user) {
            return $query;
        }

        if ($module && ! $this->hasModuleAccess($module, $user)) {
            return $query->whereRaw('1 = 0');
        }

        $userIds = $this->visibleCreatorIds($user);

        if ($userIds === null) {
            return $query;
        }

        $field = $field ?: 'creator_id';

        return $query->where(function ($query) use ($userIds, $field) {
            $query->whereIn($field, $userIds);

            // 历史 / 系统数据没有归属人，默认仍然可见（可由 config/catch.php 关闭）
            if (config('catch.data_scope_include_unowned', true)) {
                $query->orWhere($field, 0)->orWhereNull($field);
            }
        });
    }

    /**
     * 清空单次请求内的缓存
     */
    public function flush(): void
    {
        static::$cache = [];
    }

    /**
     * 按角色的数据权限取可见用户
     */
    protected function userIdsByDataRange(mixed $role, mixed $user): Collection
    {
        $dataRange = (int) $role->data_range;

        if (DataRangeEnum::Personal_Choose->assert($dataRange)) {
            return $this->userIdsByDepartments($role->departments()->pluck('id')->all());
        }

        if (DataRangeEnum::Personal_Data->assert($dataRange)) {
            return Collection::make([$user->id]);
        }

        if (DataRangeEnum::Department_Data->assert($dataRange)) {
            return $this->userIdsByDepartments([$user->department_id]);
        }

        if (DataRangeEnum::Department_DOWN_Data->assert($dataRange)) {
            $departmentId = (int) $user->department_id;

            if (! $departmentId) {
                return Collection::make();
            }

            return $this->userIdsByDepartments(
                array_merge([$departmentId], app(Departments::class)->findFollowDepartments([$departmentId]))
            );
        }

        return Collection::make();
    }

    /**
     * 取部门下的用户
     */
    protected function userIdsByDepartments(array|Collection $departmentIds): Collection
    {
        $departmentIds = Collection::make($departmentIds)
            ->filter(fn ($id) => ! empty($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($departmentIds)) {
            return Collection::make();
        }

        return app(getAuthUserModel())->newQuery()->whereIn('department_id', $departmentIds)->pluck('id');
    }

    /**
     * 用户的角色
     */
    protected function rolesOf(mixed $user): Collection
    {
        if (! method_exists($user, 'roles')) {
            return Collection::make();
        }

        return $user->roles()->get();
    }

    /**
     * 用户已授权的模块
     *
     * @return array|null null 表示无法判定（权限模块未启用）
     */
    protected function resolveModules(mixed $user): ?array
    {
        if (! method_exists($user, 'withPermissions')) {
            return null;
        }

        $user->withPermissions();

        $permissions = $user->getAttribute('permissions');

        if ($permissions === null) {
            return null;
        }

        return Collection::make($permissions)->pluck('module')->filter()->unique()->values()->all();
    }
}
