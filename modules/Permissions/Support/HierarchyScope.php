<?php
declare(strict_types=1);

namespace Modules\Permissions\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * 权限体系数据（角色 / 部门）的层级可见范围
 *
 * 两个维度取并集，缺一不可：
 *   1. creator_id（归属链）：本人 + 本人创建的账号（递归）建出来的节点。
 *      上级看得到下级建的，下级看不到上级建的；
 *   2. parent_id（层级树）：链上任一节点的整棵子树。
 *      下级在自己节点下面继续细分，上级顺着 parent_id 一样看得到。
 *
 * 这里刻意不看两样东西：
 *   - 「本人所属部门」：部门是超管建好再分配给管理员的，不等于归他管；
 *   - 「本人拥有的角色」：角色同理，是超管授予的，不是他建的。
 * 之前就是因为把这两样算进可见范围，管理员一登录又能看到、改到超管的角色和部门。
 *
 * 「无主」节点（creator_id = 0）**不参与可见性判断**：
 * 角色 / 部门 / 岗位是权限体系自身的数据，creator_id = 0 只可能是系统预置或历史遗留，
 * 按 catch.data_scope_include_unowned 兜底放给所有管理员，等于给隔离开了后门——
 * 管理员一登录就又看到一堆「不知道谁建的」角色。需要共享就给它指定归属人。
 */
class HierarchyScope
{
    public function __construct(
        protected readonly DataScope $dataScope
    ) {
    }

    /**
     * @return array|null null 表示不限制（超管 / 无登录用户）
     */
    public function visibleIds(Model $model, mixed $user = null): ?array
    {
        $user = $user ?: $this->dataScope->user();

        if (! $user) {
            return null;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return null;
        }

        $keyName = $model->getKeyName();
        $parentColumn = $model->getParentIdColumn();

        // 第一维：归属链上直接创建的节点（不含无主节点）
        $seeds = $this->dataScope
            ->applyOwnership($model->newQuery(), $user, null, false)
            ->pluck($keyName)
            ->map(fn ($id) => (int) $id)
            ->all();

        // 第二维：这些节点的整棵子树
        return $this->withDescendants($model, $seeds, $parentColumn);
    }

    /**
     * 取种子节点的整棵子树（含种子本身）
     */
    protected function withDescendants(Model $model, array $ids, string $parentColumn): array
    {
        if (empty($ids)) {
            return [];
        }

        $keyName = $model->getKeyName();

        $children = [];

        foreach ($model->newQuery()->select([$keyName, $parentColumn])->get() as $row) {
            $children[(int) $row->getAttribute($parentColumn)][] = (int) $row->getKey();
        }

        $visited = [];
        $stack = array_map('intval', $ids);

        while ($stack) {
            $id = (int) array_pop($stack);

            if (isset($visited[$id])) {
                continue;
            }

            $visited[$id] = true;

            foreach ($children[$id] ?? [] as $child) {
                if (! isset($visited[$child])) {
                    $stack[] = $child;
                }
            }
        }

        return array_map('intval', array_keys($visited));
    }
}
