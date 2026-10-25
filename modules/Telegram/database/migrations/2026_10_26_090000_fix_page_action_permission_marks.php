<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 修正动作权限项的 permission_mark：必须以「控制器名」为前缀
 *
 * 背景：User::can()（modules/User/Models/Traits/UserRelations.php:103）是这样匹配的：
 *
 *     CatchAdmin::getModuleControllerNamespace($permission->module)
 *         . ucfirst($controller) . 'Controller@' . $action
 *
 * 其中 $controller 取 permission_mark 里 '@' 前面的部分。也就是说
 * **mark 前缀必须等于控制器类名**（ucfirst 之后），否则永远匹配不上：
 *
 *     groups@destroy     → ...Controllers\GroupsController@destroy      ✗ 类不存在
 *     botGroups@destroy  → ...Controllers\BotGroupsController@destroy   ✓
 *
 * 而菜单的 permission_mark 是「前端路由名」，跟控制器名并不一致
 * （群列表菜单叫 groups，控制器却叫 BotGroupsController），
 * 所以按菜单 mark 生成的动作项全是无效项 —— 后台勾了也还是 10003。
 *
 * 本迁移做两件事：
 *   1) 删掉 mark 前缀找不到对应控制器的动作项（含上一迁移生成的错项）
 *   2) 按「菜单 mark → 控制器名」映射重新生成，幂等
 */
return new class extends Migration
{
    /**
     * 菜单 permission_mark => 控制器名（ucfirst 后接 'Controller' 必须是真实存在的类）
     *
     * 映射来源：菜单 component 路径（如 /telegram/botGroups/index.vue）对应的后台控制器。
     */
    private array $menuToController = [
        // 号码采集
        'phone'           => 'phone',
        'sCanLog'         => 'sCanLog',
        'telegramApiUser' => 'telegramApiUser',
        'apiuser'         => 'telegramApiUser',
        // 附加功能
        'funs'            => 'features',
        'tconf'           => 'thridConfig',
        'tendpoint'       => 'thirdApiEndpoints',
        'tconfendpoint'   => 'thirdConfigEndpoints',
        // 机器人管理
        'bots'            => 'bots',
        'groupgroups'     => 'botGroupGroup',
        'groups'          => 'botGroups',
        // 钱包管理
        'walletMember'        => 'member',
        'walletAccount'       => 'wallet',
        'walletRechargeOrder' => 'rechargeOrder',
        'walletWithdrawOrder' => 'withdrawOrder',
        'walletLedger'        => 'ledger',
        'walletChannel'       => 'paymentChannel',
        'walletRisk'          => 'riskControl',
        'walletLimit'         => 'transactionLimit',
        'walletRate'          => 'exchangeRate',
    ];

    /** 与 Modules\Permissions\Models\Permissions::$defaultActions 一致 */
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
        $this->dropUnmatchedActions();

        $now = time();
        $module = 'telegram';
        $actionType = 3; // MenuType::Action

        $menus = DB::table('permissions')
            ->where('module', $module)
            ->where('type', 2)
            ->where('permission_mark', '<>', '')
            ->get(['id', 'permission_mark']);

        foreach ($menus as $menu) {
            $controller = $this->menuToController[$menu->permission_mark] ?? null;

            if (! $controller) {
                Log::warning('菜单没有配置对应的控制器映射，跳过生成动作权限', [
                    'menu' => $menu->permission_mark,
                ]);

                continue;
            }

            $sort = 1;

            foreach ($this->resolveActions($controller) as $action => $name) {
                $mark = $controller . '@' . $action;

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
     * 删掉 mark 前缀找不到对应控制器的动作项
     */
    private function dropUnmatchedActions(): void
    {
        $namespace = 'Modules\\Telegram\\Http\\Controllers\\';

        $actions = DB::table('permissions')
            ->where('module', 'telegram')
            ->where('type', 3)
            ->get(['id', 'permission_mark']);

        foreach ($actions as $action) {
            $prefix = explode('@', (string) $action->permission_mark)[0] ?? '';

            if ($prefix === '' || ! class_exists($namespace . Str::ucfirst($prefix) . 'Controller')) {
                // 先解绑角色，否则 role_has_permissions 里会留下指向已删权限的孤儿行
                DB::table('role_has_permissions')->where('permission_id', $action->id)->delete();

                DB::table('permissions')->where('id', $action->id)->delete();
            }
        }
    }

    /**
     * 控制器上真实存在的动作：
     *   1) defaultActions ∩ public 方法（含继承自基类的 index/store/...）
     *   2) 控制器自己声明的其它 public 方法（自定义动作）
     */
    private function resolveActions(string $controller): array
    {
        $class = 'Modules\\Telegram\\Http\\Controllers\\' . Str::ucfirst($controller) . 'Controller';

        if (! class_exists($class)) {
            return [];
        }

        $reflection = new \ReflectionClass($class);

        $publicMethods = [];
        $ownMethods = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if ($method->isConstructor() || $method->isStatic() || Str::startsWith($name, '__')) {
                continue;
            }

            $publicMethods[] = $name;

            if ($method->getDeclaringClass()->getName() === ltrim($class, '\\')) {
                $ownMethods[] = $name;
            }
        }

        $actions = [];

        foreach ($publicMethods as $method) {
            if (isset($this->defaultActions[$method])) {
                $actions[$method] = $this->defaultActions[$method];
            }
        }

        foreach ($ownMethods as $method) {
            if (! isset($actions[$method])) {
                $actions[$method] = $method;
            }
        }

        return $actions;
    }

    public function down(): void
    {
        //
    }
};
