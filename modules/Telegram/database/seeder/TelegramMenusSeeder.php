<?php

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

return new class extends Seeder
{
    /**
     * Run the seeder.
     *
     * @return void
     */
    public function run(): void
    {
        $menus = $this->alignExistingMarks($this->menus());

        importTreeData($menus, 'permissions');
    }

    /**
     * 把 permission_mark 对齐到库中已有的同路由菜单，保证 seed 幂等。
     *
     * ⚠️ importTreeData() 的查重键是 permission_name + module + permission_mark，
     * 不包含 route。老库里「功能列表 / 三方配置 / 群分组 / 群列表」的 mark 是
     * 历史值（features / thridConfig / botGroupGroup / botGroups），而本 seeder
     * 用 route 当 mark（funs / tconf / groupgroups / groups）。三键不一致时
     * helper 会认为「不存在」并再插一条，后台侧边栏就出现同名菜单两份。
     *
     * 导入前按 module + route + type 找到库里已存在的记录（同路由多条时取 id
     * 最小的旧记录），把待导入节点的 mark 对齐成它的 mark，既让查重必然命中，
     * 又不用把历史命名硬编码进 seeder；全新库没有已有记录时保持原值。
     */
    private function alignExistingMarks(array $menus): array
    {
        foreach ($menus as &$menu) {
            $existing = DB::table('permissions')
                ->where('module', $menu['module'])
                ->where('route', $menu['route'])
                ->where('type', $menu['type'])
                ->orderBy('id')
                ->first();

            $mark = $existing ? (string) ($existing->permission_mark ?? '') : null;
            if ($mark !== null && $mark !== (string) ($menu['permission_mark'] ?? '')) {
                $menu['permission_mark'] = $mark;
            }

            if (! empty($menu['children'])) {
                $menu['children'] = $this->alignExistingMarks($menu['children']);
            }
        }
        unset($menu);

        return $menus;
    }

    /**
     * Telegram 模块后台菜单。
     *
     * ⚠️ 必须用「嵌套 children」结构，不能平铺写 parent_id：
     *    importTreeData() 会 unset(id) 由数据库自增长分配新 id，
     *    平铺数据的 parent_id 是写死的旧 id，导入后会指向不存在的记录，
     *    导致所有子菜单成为孤儿（表现为菜单点开空白/结构错乱）。
     *    嵌套结构下它由递归把 children 的 parent_id 设为父记录的新 id，永远正确。
     *
     * 字段约定：
     *   type = 1 目录（component 用 /layout/index.vue，前端按目录渲染）
     *   type = 2 页面（component 指向 web/src/views 下的真实 .vue 文件）
     *   redirect：目录点击后跳转的目标，不设则点击目录只会渲染空 layout（表现为「没反应」）
     *
     * 组件路径必须对应真实文件（相对 web/src/views），否则页面空白且不发请求。
     *
     * ⚠️ 侧边栏标题不读 permission_name：前端 Menu/item.vue 用
     *    $t('menu.' + 完整path) 取文案。新增页面必须同步在
     *    web/src/i18n/languages/{zh,en,vi,th}.ts 的 menu 段加对应 key，
     *    否则侧边栏会直接显示 "menu./xxx" 这样的 key 原文。
     */
    public function menus(): array
    {
        $now = time();

        $page = function (string $name, string $route, string $component, string $icon, int $sort) use ($now): array {
            return [
                'permission_name' => $name,
                'route'           => $route,
                'icon'            => $icon,
                'module'          => 'telegram',
                'permission_mark' => $route,
                'component'       => $component,
                'keepalive'       => 1,
                'type'            => 2,
                'hidden'          => 0,
                'sort'            => $sort,
                'creator_id'      => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
            ];
        };

        return [
            [
                'permission_name' => '号码采集',
                'route'           => '/collect',
                'icon'            => 'book-open',
                'module'          => 'telegram',
                'permission_mark' => '',
                'component'       => '/layout/index.vue',
                // 不要设 redirect：父菜单有 children 时前端渲染为「可展开 subMenu」
                // （点击展开子菜单，不跳转路由）。若再给父路由加 redirect 指向子路由，
                // 访问子路径时会反复命中父路由的 redirect，造成重定向死循环（页面刷屏）。
                'redirect'        => '',
                'keepalive'       => 1,
                'type'            => 1,
                'hidden'          => 0,
                'sort'            => 1,
                'creator_id'      => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
                'children'        => [
                    $page('号码仓库', 'phone', '/telegram/phone/index.vue', 'folder', 1),
                    $page('扫描记录', 'sCanLog', '/telegram/sCanLog/index.vue', 'clipboard-document-list', 2),
                    // 注意：原数据这里指向 /telegram/tUser/index.vue，但 tUser 已合并进
                    // telegramApiUser（见迁移 merge_tusers_into_telegram_api_users），
                    // 前端不存在 tUser 目录，会导致页面空白，故改为 telegramApiUser。
                    $page('账号仓库', 'telegramApiUser', '/telegram/telegramApiUser/index.vue', 'circle-stack', 3),
                ],
            ],
            [
                'permission_name' => '附加功能',
                'route'           => '/activity',
                'icon'            => 'briefcase',
                'module'          => 'telegram',
                'permission_mark' => '',
                'component'       => '/layout/index.vue',
                'redirect'        => '',
                'keepalive'       => 1,
                'type'            => 1,
                'hidden'          => 0,
                'sort'            => 2,
                'creator_id'      => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
                'children'        => [
                    $page('功能列表', 'funs', '/telegram/features/index.vue', 'paper-airplane', 1),
                    $page('三方配置', 'tconf', '/telegram/thridConfig/index.vue', 'cog-6-tooth', 2),
                    $page('三方接口', 'tendpoint', '/telegram/thirdEndpoint/index.vue', 'link', 3),
                    // 为每个上游单独配置某接口的请求路径（同一功能在不同上游路径不同）
                    $page('上游接口配置', 'tconfendpoint', '/telegram/thirdConfigEndpoint/index.vue', 'arrows-right-left', 4),
                ],
            ],
            [
                'permission_name' => '机器人管理',
                'route'           => '/telegram',
                'icon'            => 'adjustments-horizontal',
                'module'          => 'telegram',
                'permission_mark' => '',
                'component'       => '/layout/index.vue',
                'redirect'        => '',
                'keepalive'       => 1,
                'type'            => 1,
                'hidden'          => 0,
                'sort'            => 3,
                'creator_id'      => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
                'children'        => [
                    $page('Telegram客服', 'apiuser', '/telegram/telegramApiUser/index.vue', 'users', 1),
                    $page('机器人列表', 'bots', '/telegram/bots/index.vue', 'wrench-screwdriver', 2),
                    $page('群分组', 'groupgroups', '/telegram/botGroupGroup/index.vue', 'folder', 3),
                    $page('群列表', 'groups', '/telegram/botGroups/index.vue', 'home', 4),
                ],
            ],
            [
                'permission_name' => '钱包管理',
                'route'           => '/wallet',
                'icon'            => 'wallet',
                'module'          => 'telegram',
                'permission_mark' => '',
                'component'       => '/layout/index.vue',
                'redirect'        => '',
                'keepalive'       => 1,
                'type'            => 1,
                'hidden'          => 0,
                'sort'            => 4,
                'creator_id'      => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
                'children'        => [
                    $page('会员', 'walletMember', '/telegram/wallet/member/index.vue', 'users', 1),
                    $page('钱包', 'walletAccount', '/telegram/wallet/wallet/index.vue', 'wallet', 2),
                    $page('充值订单', 'walletRechargeOrder', '/telegram/wallet/rechargeOrder/index.vue', 'banknotes', 3),
                    $page('提现订单', 'walletWithdrawOrder', '/telegram/wallet/withdrawOrder/index.vue', 'arrow-up-tray', 4),
                    $page('账本流水', 'walletLedger', '/telegram/wallet/ledger/index.vue', 'document-text', 5),
                    $page('支付通道', 'walletChannel', '/telegram/wallet/paymentChannel/index.vue', 'credit-card', 6),
                    $page('风控中心', 'walletRisk', '/telegram/wallet/riskControl/index.vue', 'shield-check', 7),
                    $page('交易限额', 'walletLimit', '/telegram/wallet/transactionLimit/index.vue', 'adjustments-horizontal', 8),
                    $page('汇率', 'walletRate', '/telegram/wallet/exchangeRate/index.vue', 'arrows-right-left', 9),
                ],
            ],
            [
                'permission_name' => '平台管理',
                'route'           => '/platform',
                'icon'            => 'building-office',
                'module'          => 'telegram',
                'permission_mark' => '',
                'component'       => '/layout/index.vue',
                'redirect'        => '',
                'keepalive'       => 1,
                'type'            => 1,
                'hidden'          => 0,
                'sort'            => 5,
                'creator_id'      => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
                'children'        => [
                    $page('商户管理', 'merchants', '/telegram/merchants/index.vue', 'building-office', 1),
                ],
            ],
            [
                'permission_name' => '使用帮助',
                'route'           => '/help',
                'icon'            => 'question-mark-circle',
                'module'          => 'telegram',
                'permission_mark' => '',
                'component'       => '/layout/index.vue',
                'redirect'        => '',
                'keepalive'       => 1,
                'type'            => 1,
                'hidden'          => 0,
                'sort'            => 6,
                'creator_id'      => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
                'children'        => [
                    $page('快速上手', 'quickstart', '/telegram/help/quickstart.vue', 'light-bulb', 1),
                    $page('功能说明', 'features', '/telegram/help/features.vue', 'squares-2x2', 2),
                ],
            ],
        ];
    }
};
