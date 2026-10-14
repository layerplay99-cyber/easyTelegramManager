<?php

namespace Modules\User\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Exceptions\FailedException;
use Catch\Support\Module\ModuleRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Permissions\Models\Departments;
use Modules\Permissions\Support\DataScope;
use Modules\Permissions\Support\HierarchyScope;
use Modules\User\Models\LogLogin;
use Modules\User\Models\LogOperate;
use Modules\User\Models\User;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Modules\User\Http\Requests\UserRequest;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        protected readonly User $user
    ) {
    }

    /**
     * get list
     *
     * @return mixed
     */
    public function index()
    {
        return $this->user->setBeforeGetList(function ($query){
            // 数据范围（本人 + 下级 + 角色数据权限）由 User 模型的 DataRange trait 自动生效
            if (! $this->getLoginUser()->isSuperAdmin()) {
                $query = $query->where('id', '<>', config('catch.super_admin'));
            }

            if (\request()->has('department_id')) {
                $departmentId = \request()->get('department_id');
                $followDepartmentIds = app(Departments::class)->findFollowDepartments(\request()->get('department_id'));
                $followDepartmentIds[] = $departmentId;
                $query = $query->whereIn('department_id', $followDepartmentIds);
            }

            return $query;
        })->getList();
    }

    /**
     * Command
     *
     * @param UserRequest $request
     * @return false|mixed
     */
    public function store(UserRequest $request)
    {
        $data = $request->all();

        $loginUser = $this->getLoginUser();

        // 部门管理员建号只能落在自己（及自己建的账号）创建的部门里：
        // 数据范围（本部门 / 本部门及以下）是按 users.department_id 反推可见用户的，
        // 允许自选部门就等于能自选数据范围，直接把隔离打穿。
        if (! $loginUser->isSuperAdmin()) {
            $data['department_id'] = $this->assignableDepartmentId($data['department_id'] ?? null, $loginUser);
        }

        return $this->user->storeBy($data);
    }

    /**
     * show
     *
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $user = $this->user->firstBy($id);

        $this->assertOperableUser($user, $this->getLoginUser());

        $user->makeHidden([
            'password',
            'two_factor_secret',
            'two_factor_secret_temp',
        ]);

        if (app(ModuleRepository::class)->enabled('permissions')) {
            $user->setRelations([
                'roles' => $user->roles->pluck('id'),

                'jobs' => $user->jobs->pluck('id')
            ]);
        }

        return $user;
    }

    /**
     * update
     *
     * @param $id
     * @param UserRequest $request
     * @return mixed
     */
    public function update($id, UserRequest $request)
    {
        $data = $request->all();

        $loginUser = $this->getLoginUser();

        if (! $loginUser->isSuperAdmin()) {
            $this->assertOperableUser($this->user->firstBy($id), $loginUser);

            // 同上：不允许把账号（自己或下级）挪到别人的部门
            $data['department_id'] = $this->assignableDepartmentId($data['department_id'] ?? null, $loginUser);
        }

        return $this->user->updateBy($id, $data);
    }

    /**
     * destroy
     *
     * @param $id
     * @return bool|null
     */
    public function destroy($id)
    {
        // 先吊销令牌再删除用户：原来 $this->user 是全新实例（没有 id），
        // tokens() 会去删 tokenable_id 为空的记录，被删用户的令牌实际一直有效。
        $user = $this->user->firstBy($id);

        $this->assertOperableUser($user, $this->getLoginUser());

        if ($user) {
            $user->tokens()->delete();
        }

        return $this->user->deleteBy($id);
    }

    /**
     * enable
     *
     * @param $id
     * @return bool
     */
    public function enable($id)
    {
        $this->assertOperableUser($this->user->firstBy($id), $this->getLoginUser());

        return $this->user->toggleBy($id);
    }

    /**
     * 部门归属：只允许落在自己（及自己建的账号）创建的部门里
     *
     * 传了别人的部门、或者压根没传，一律退回自己的所属部门。
     * 允许自选部门就等于自选数据范围（本部门 / 本部门及以下），隔离会被打穿。
     */
    protected function assignableDepartmentId(mixed $departmentId, mixed $loginUser): int
    {
        $visible = app(HierarchyScope::class)->visibleIds(app(Departments::class), $loginUser) ?? [];

        $departmentId = (int) $departmentId;

        if ($departmentId && in_array($departmentId, $visible, true)) {
            return $departmentId;
        }

        return (int) $loginUser->department_id;
    }

    /**
     * 越权拦截：只能操作自己、以及自己（递归）创建的账号
     *
     * 超管账号单独挡一道——他的 creator_id 未必落在别人的归属链外
     * （比如超管的 creator_id 可能就是他自己，剩下一堆账号的 creator_id 也是他），
     * 光按归属链判断不足以保护。
     */
    protected function assertOperableUser(mixed $target, mixed $loginUser): void
    {
        if (! $target || $loginUser->isSuperAdmin()) {
            return;
        }

        if ((int) $target->getKey() === (int) config('catch.super_admin')) {
            throw new FailedException('无权操作超级管理员账号');
        }

        // 自己改自己（改密码、改昵称）永远是允许的
        if ((int) $target->getKey() === (int) $loginUser->id) {
            return;
        }

        app(DataScope::class)->assertInOwnershipChain($target, $loginUser, '只能操作自己及自己创建的用户');
    }

    /**
     *  online user
     *
     * @return Authenticatable
     */
    public function online(Request $request)
    {
        /* @var User $user */
        $user = $this->getLoginUser()->withPermissions();

        if ($request->isMethod('post')) {
            return $user->updateBy($user->id, $request->all());
        }
        $user->makeHidden('two_factor_secret');
        return $user;
    }

    public function getTwoFactorStatus()
    {
        $user = $this->getLoginUser();
        return ['two_factor_enabled' => (bool)$user->two_factor_enabled];
    }

    public function setTwoFactorStatus(Request $request,string $id)
    {
        // 权限校验原来被整段注释掉了，导致任意登录用户可以关掉别人的 2FA，
        // 再配合 AuthController 里「未开启 2FA 就直接发 token」的分支形成完整绕过链。
        $loginUser = $this->getLoginUser();

        // 只允许超管修改他人，普通用户只能改自己
        if ((int) $id !== (int) $loginUser->id && ! $loginUser->isSuperAdmin()) {
            return response(['message' => '无权限操作'], 403);
        }

        $user = User::findOrFail($id);
        $user->two_factor_enabled = (bool)$request->input('two_factor_enabled', false);
        $user->save();

        return ['success' => true, 'two_factor_enabled' => (bool)$user->two_factor_enabled];
    }

    /**
     * login log
     * @param LogLogin $logLogin
     * @return LengthAwarePaginator
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function loginLog(LogLogin $logLogin)
    {
        $user = $this->getLoginUser();

        return $logLogin->getUserLogBy($user->isSuperAdmin() ? null : $user->email);
    }

    public function operateLog(LogOperate $logOperate, Request $request)
    {
        $scope = $request->get('scope', 'self');
        $user = $this->getLoginUser();

        // 原来 scope=all 时不加任何限制，任意用户都能读到所有人的操作日志
        if ($scope != 'self' && ! $user->isSuperAdmin()) {
            $scope = 'self';
        }

        return $logOperate->setBeforeGetList(function ($builder) use ($scope){
            if ($scope == 'self') {
                return $builder->where('creator_id', $this->getLoginUserId());
            }
            return $builder;
        })->getList();
    }

    /**
     * @return void
     */
    public function export()
    {
        $query = User::query()
                    ->select('id', 'username', 'email', 'created_at')
                    ->without('roles');

        // 导出原来完全不过滤，任意登录用户可导出全平台账号
        if (! $this->getLoginUser()->isSuperAdmin()) {
            app(DataScope::class)->apply($query, null, null, 'creator_id');
        }

        return $query->get()->download(['id', '昵称', '邮箱', '创建时间']);
    }
}
