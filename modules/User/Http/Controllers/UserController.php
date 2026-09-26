<?php

namespace Modules\User\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Support\Module\ModuleRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Permissions\Models\Departments;
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
            if (! $this->getLoginUser()->isSuperAdmin()) {
                $query = $query->where('id', '<>', config('catch.super_admin'));
                $query->where('department_id', $this->getLoginUser()->department_id);
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
        return $this->user->storeBy($request->all());
    }

    /**
     * show
     *
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $user = $this->user->firstBy($id)->makeHidden([
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
        return $this->user->updateBy($id, $request->all());
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
        return $this->user->toggleBy($id);
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
        return User::query()
                    ->select('id', 'username', 'email', 'created_at')
                    ->without('roles')
                    ->get()
                    ->download(['id', '昵称', '邮箱', '创建时间']);
    }
}
