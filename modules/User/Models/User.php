<?php

namespace Modules\User\Models;

use Catch\Base\CatchModel as Model;
use Catch\Enums\Status;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Modules\User\Models\Traits\UserRelations;
use Illuminate\Auth\Authenticatable;

/**
 * @property int $id
 * @property string $username
 * @property string $email
 * @property string $avatar
 * @property string $password
 * @property int $creator_id
 * @property int $status
 * @property string $login_ip
 * @property int $login_at
 * @property int $created_at
 * @property int $updated_at
 * @property string $remember_token
 */
class User extends Model implements AuthenticatableContract
{
    use Authenticatable, UserRelations, HasApiTokens;

    protected $fillable = [
        'id',
        'username',
        'email',
        'avatar',
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_enabled',
        'two_factor_secret_temp',
        'two_factor_enabled_at',
        'creator_id',
        'status',
        'department_id',
        'login_ip',
        'login_at',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    // 2FA 密钥必须默认隐藏：原来只有 online() 里手动 makeHidden 了一次，
    // show() 只隐藏 password，2FA secret 会被直接返回给前端。
    protected array $defaultHidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_secret_temp',
    ];

    /**
     * @var array|string[]
     */
    public array $searchable = [
        'username' => 'like',
        'email' => 'like',
        'status' => '=',
    ];

    /**
     * @var string
     */
    protected $table = 'users';

    protected array $fields = ['id', 'username', 'email', 'avatar', 'two_factor_enabled',  'creator_id', 'status', 'department_id', 'created_at'];

    /**
     * @var array|string[]
     */
    protected array $form = ['username', 'email', 'password', 'two_factor_enabled', 'department_id'];

    /**
     * @var array|string[]
     */
    protected array $formRelations = ['roles', 'jobs'];

    /**
     * password
     *
     * @return Attribute
     */
    protected function password(): Attribute
    {
        return new Attribute(
            // get: fn($value) => '',
            set: fn ($value) => bcrypt($value),
        );
    }

    protected function DepartmentId(): Attribute
    {
        return new Attribute(
            get: fn($value) => $value ? : null,
            set: fn($value) => $value ? : 0
        );
    }

    /**
     * is super admin
     *
     * @return bool
     */
    public function isSuperAdmin(): bool
    {
        return $this->{$this->primaryKey} == config('catch.super_admin');
    }

    /**
     * update
     * @param $id
     * @param array $data
     * @return mixed
     */
    public function updateBy($id, array $data): mixed
    {
        if (empty($data['password'])) {
            unset($data['password']);
        }

        return parent::updateBy($id, $data);
    }

    public function isDisabled(): bool
    {

        return $this->status == Status::Disable->value;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return !empty($this->two_factor_secret);
    }

    function getUserIdsByRoleIds(array $roleIds): array
    {
        return DB::table('user_has_roles')
            ->whereIn('role_id', $roleIds)
            ->pluck('user_id')
            ->unique()
            ->toArray();
    }
}
