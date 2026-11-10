<?php

namespace Modules\User\Models;

use Modules\Permissions\Models\Traits\DataRange;
use Modules\Permissions\Support\MerchantContext;

use Catch\Base\CatchModel as Model;
use Catch\Enums\Status;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Cache;
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
 * @property \Illuminate\Support\Carbon|null $expired_at
 * @property \Illuminate\Support\Carbon|null $expire_noticed_at
 * @property string $login_ip
 * @property int $login_at
 * @property int $created_at
 * @property int $updated_at
 * @property string $remember_token
 */
class User extends Model implements AuthenticatableContract
{
    use DataRange;

    use Authenticatable, UserRelations, HasApiTokens;

    /** 有效期字段用日期时间类型，不受 CatchModel 整型时间戳约定影响 */
    protected $casts = [
        'expired_at' => 'datetime',
        'expire_noticed_at' => 'date',
        'balance' => 'decimal:8',
    ];

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
        'expired_at',
        'expire_noticed_at',
        'balance',
        'department_id',
        'login_ip',
        'login_at',
        'created_at',
        'updated_at',
        'deleted_at',
        'tenant_id',
        'is_merchant_admin'
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

    protected array $fields = ['id', 'username', 'email', 'avatar', 'two_factor_enabled', 'creator_id', 'status', 'expired_at', 'balance', 'department_id', 'tenant_id', 'is_merchant_admin', 'created_at'];

    /**
     * @var array|string[]
     */
    protected array $form = ['username', 'email', 'password', 'two_factor_enabled', 'department_id', 'tenant_id', 'is_merchant_admin', 'expired_at'];

    /**
     * 归属商户与商户超管标记只允许平台超管显式指定。
     *
     * filterData() 是 storeBy/updateBy 的统一入口，在这里收口就覆盖了
     * 管理员列表的全部增改请求。商户用户伪造 tenant_id 会被丢掉，随后由
     * AppServiceProvider 的 creating/updating 钩子按登录态强制继承自己的租户。
     */
    protected function filterData(array $data): array
    {
        if (! MerchantContext::isPlatformSuperAdmin()) {
            unset($data['tenant_id'], $data['is_merchant_admin']);
        }

        return parent::filterData($data);
    }

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

    /**
     * 商户账号是否已过有效期（expired_at 为空 = 永久有效）
     */
    public function isExpired(): bool
    {
        return $this->expired_at !== null && $this->expired_at->isPast();
    }

    /**
     * 商户有效期缓存的 key（UpdateRouter 拦截用；改有效期后要清）
     */
    public static function expiryCacheKey(int $userId): string
    {
        return 'merchant_expired:' . $userId;
    }

    protected static function booted(): void
    {
        // 有效期变更/删除用户后立即清掉两类缓存（5 分钟 TTL）：
        //   过期判定（指令链路拦截）+ 付费准入（付费功能校验）
        $purge = function (self $user): void {
            Cache::forget(self::expiryCacheKey((int) $user->id));
            Cache::forget(\App\Support\ServiceExpiry::paidCacheKey((int) $user->id));
        };

        static::saved($purge);
        static::deleted($purge);
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
