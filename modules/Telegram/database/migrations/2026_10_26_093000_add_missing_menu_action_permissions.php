<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 补齐「共用同一控制器」的菜单下缺失的动作权限项
 *
 * 背景：「Telegram 客服」（mark=apiuser）和「账号仓库」（mark=telegramApiUser）
 * 是同一个页面（component 都是 /telegram/telegramApiUser/index.vue），
 * 控制器都是 TelegramApiUserController，动作项的 mark 也就都是 telegramApiUser@*。
 *
 * 上一个迁移按 (module, permission_mark) 判重，于是只在先遍历到的那个菜单下
 * 生成了一份，另一个菜单下被判成「已存在」直接跳过 —— 表现为那个菜单
 * （Telegram 客服）下面没有可勾的动作项。
 *
 * permission_mark 上只有普通索引、没有唯一约束，所以同一个 mark 挂在不同
 * parent 下是允许的。这里改成按 (module, permission_mark, parent_id) 判重。
 */
return new class extends Migration
{
    /** 菜单 permission_mark => 控制器名（与 2026_10_26_090000 保持一致的映射） */
    private array $menuToController = [
        'phone'           => 'phone',
        'sCanLog'         => 'sCanLog',
        'telegramApiUser' => 'telegramApiUser',
        'apiuser'         => 'telegramApiUser',
        'funs'            => 'features',
        'tconf'           => 'thridConfig',
        'tendpoint'       => 'thirdApiEndpoints',
        'tconfendpoint'   => 'thirdConfigEndpoints',
        'bots'            => 'bots',
        'groupgroups'     => 'botGroupGroup',
        'groups'          => 'botGroups',
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

                // 关键：同一个 mark 要在每个菜单下各有一份，所以判重要带上 parent_id
                $exists = DB::table('permissions')
                    ->where('module', $module)
                    ->where('permission_mark', $mark)
                    ->where('parent_id', $menu->id)
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
