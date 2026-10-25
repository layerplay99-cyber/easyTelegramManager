<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 为 Telegram 模块的页面菜单补齐「动作（按钮）」权限项
 *
 * 背景：
 *   TelegramMenusSeeder 只建了目录(type=1) 和页面(type=2)，从来没有建过动作(type=3)。
 *   而 User::can()（modules/User/Models/Traits/UserRelations.php）只看 type=3 的权限项，
 *   拼成 Modules\Telegram\Http\Controllers\XxxController@action 去匹配当前路由：
 *     - 超级管理员 isSuperAdmin() 直接 return true，所以这个问题一直没暴露
 *     - 普通管理员做任何非 GET 操作都被 PermissionGate 拦成 10003 permission forbidden
 *     - 后台「角色 → 权限」树里页面下面没有第三级，想授权都无处可勾
 *
 * 本迁移按「控制器真实存在的 public 方法」补齐，幂等（按 module + permission_mark 判重）。
 * 只补数据、不给任何角色自动授权 —— 授权仍由超管在角色里勾选（最小权限）。
 */
return new class extends Migration
{
    /**
     * 与 Modules\Permissions\Models\Permissions::$defaultActions 保持一致
     */
    private array $defaultActions = [
        'index'   => '列表',
        'store'   => '新增',
        'show'    => '读取',
        'update'  => '更新',
        'destroy' => '删除',
        'enable'  => '禁用/启用',
        'import'  => '导入',
        'export'  => '导出',
    ];

    public function up(): void
    {
        $now = time();
        $module = 'telegram';
        $actionType = 3; // Modules\Permissions\Enums\MenuType::Action

        $menus = DB::table('permissions')
            ->where('module', $module)
            ->where('type', 2)
            ->where('permission_mark', '<>', '')
            ->get(['id', 'permission_mark']);

        foreach ($menus as $menu) {
            $sort = 1;
            foreach ($this->resolveActions($menu->permission_mark) as $action => $name) {
                $mark = $menu->permission_mark . '@' . $action;

                $exists = DB::table('permissions')
                    ->where('module', $module)
                    ->where('permission_mark', $mark)
                    ->exists();

                if ($exists) {
                    $sort++;
                    continue;
                }

                DB::table('permissions')->insert([
                    'parent_id'       => $menu->id,
                    'permission_name' => $name,
                    'route'           => '',
                    'icon'            => '',
                    'module'          => $module,
                    'permission_mark' => $mark,
                    'component'       => '',
                    'redirect'        => '',
                    'keepalive'       => 1,
                    'type'            => $actionType,
                    'hidden'          => 1,
                    'sort'            => $sort++,
                    'active_menu'     => '',
                    'creator_id'      => 1,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                    'deleted_at'      => 0,
                ]);
            }
        }
    }

    /**
     * 某页面菜单下应有的动作：
     *   1) 控制器里真实存在的标准动作（defaultActions ∩ public 方法）
     *   2) 控制器自己声明的其它 public 方法（自定义动作，如 BotsController@sendToGroup）。
     *      这些不在 defaultActions 里，但同样是路由动作，没有权限项就永远无法授权。
     *
     * 控制器不存在（页面 mark 与控制器名不一致）时，退化为只补五个基本动作。
     */
    private function resolveActions(string $mark): array
    {
        $controller = 'Modules\\Telegram\\Http\\Controllers\\' . Str::studly($mark) . 'Controller';

        if (! class_exists($controller)) {
            return array_intersect_key(
                $this->defaultActions,
                array_flip(['index', 'store', 'show', 'update', 'destroy'])
            );
        }

        $reflection = new \ReflectionClass($controller);

        $publicMethods = [];
        $ownMethods = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if ($method->isConstructor() || $method->isStatic() || Str::startsWith($name, '__')) {
                continue;
            }

            $publicMethods[] = $name;

            if ($method->getDeclaringClass()->getName() === ltrim($controller, '\\')) {
                $ownMethods[] = $name;
            }
        }

        $actions = [];

        foreach ($publicMethods as $method) {
            if (isset($this->defaultActions[$method])) {
                $actions[$method] = $this->defaultActions[$method];
            }
        }

        // 自定义动作用方法名本身作为显示名
        foreach ($ownMethods as $method) {
            if (! isset($actions[$method])) {
                $actions[$method] = $method;
            }
        }

        return $actions;
    }

    /**
     * 不回滚：权限项属于配置数据，且本迁移与其它迁移/后台手工建的动作项无法区分，
     * 盲目删除会误删历史数据。需要清理时在后台「权限管理」里按菜单删除。
     */
    public function down(): void
    {
        //
    }
};
