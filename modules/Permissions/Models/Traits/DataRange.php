<?php
declare(strict_types=1);

namespace Modules\Permissions\Models\Traits;

use Illuminate\Support\Collection;
use Modules\Permissions\Support\DataScope;

/**
 * 后台数据范围
 *
 * CatchModel::init() 会自动识别类名包含 DataRange 的 trait，
 * 并在 getList() 里调用 dataRange()，所以模型只要 `use DataRange;` 就生效。
 *
 * 模型可通过 protected string $dataModule = 'telegram'; 声明所属模块，
 * 声明后当前用户没有该模块功能权限时，该模块数据完全不可见。
 *
 * @method string aliasField(string $field)
 */
trait DataRange
{
    /**
     * 列表数据范围
     *
     * @param $query
     * @param array|Collection $roles 兼容旧签名，已由 DataScope 内部按登录用户解析
     * @return mixed
     */
    public function scopeDataRange($query, array|Collection $roles = []): mixed
    {
        return app(DataScope::class)->apply($query, null, $this->getDataModule(), $this->aliasField('creator_id'));
    }

    /**
     * 当前记录是否在登录用户可见范围内
     */
    public function isVisibleTo(mixed $user = null): bool
    {
        return app(DataScope::class)->isVisible($this, $user);
    }

    /**
     * 可见数据的 creator_id 集合，null 表示不限制
     */
    public function visibleCreatorIds(mixed $user = null): ?array
    {
        return app(DataScope::class)->visibleCreatorIds($user);
    }

    /**
     * 模型所属模块
     */
    protected function getDataModule(): ?string
    {
        return property_exists($this, 'dataModule') ? $this->dataModule : null;
    }

    /**
     * 兼容旧签名：按角色数据权限取可见用户 ID
     *
     * @deprecated 逻辑已收敛到 Modules\Permissions\Support\DataScope
     */
    public function getDepartmentUserIdsBy(array $roles, $currentUser): Collection
    {
        return Collection::make(app(DataScope::class)->visibleCreatorIds($currentUser) ?? []);
    }
}
