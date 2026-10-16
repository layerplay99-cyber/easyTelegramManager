# 数据库说明

> 相关文档：[Telegram 模块开发](telegram-module.md) · [部署](deployment.md)
> 最后更新：2026-10-16
> 本文以 `docker-compose` 本地测试库（库名 `bots`）为基准，生产库名/前缀以 `.env` 为准。

---

## 1. 命名与通用约定

| 约定 | 说明 |
|---|---|
| 表前缀 | 由 `DB_PREFIX` 决定，本文用 `bm`（表名写作 `bmxxx`）。迁移里写**裸表名**（如 `features`），前缀由 Laravel 自动补 |
| 主键 | 统一 `id` bigint unsigned 自增 |
| 软删除 | `deleted_at` int，`0` = 未删除，删除时写时间戳。查询默认带 `deleted_at = 0`；**彻底删除要用 `forceDelete()` 或直接写 DB** |
| 时间字段 | 大部分表用 `created_at` / `updated_at` **int（Unix 时间戳）**，少数新表用 `timestamp`（见 `feature_hook_routes`） |
| 归属字段 | `creator_id` = 创建人 `users.id`，`0` = 无主。数据隔离的核心字段，见第 4 节 |
| 启用开关 | `enabled` tinyint(1)，默认 1 |
| JSON 字段 | `config` / `payload` / `data` / `blocks` / `buttons` 等，模型侧 `casts => array` |

⚠️ **索引名长度**：MySQL 索引名上限 64 字符，Laravel 自动生成的名字是
`{表前缀}{表名}_{列1}_{列2}_index|_unique`。前缀较长时（如 `bot_dayang_`）很容易超，
新增多列索引**务必显式命名**。报错特征：`SQLSTATE[42000] 1059 Identifier name ... is too long`。

⚠️ **`trigger` 是 MySQL 保留字**：`bmfeatures.trigger` 列在 SQL 里必须加反引号。

---

## 2. 表清单（按业务域）

共 68 张表。归属以迁移文件所在模块为准。

### 2.1 权限与用户（`permissions` + `user` 模块 + 框架）

| 表 | 说明 |
|---|---|
| `bmusers` | 后台账号。`department_id` 所属部门、`creator_id` 创建人（隔离核心） |
| `bmroles` | 角色。`parent_id` 支持层级、`data_range` 数据范围（1 全部数据 / 2 自定义数据 / 3 本人数据 / 4 部门数据 / 5 部门及以下数据） |
| `bmdepartments` | 部门，树形（`parent_id`） |
| `bmjobs` | 岗位 |
| `bmpermissions` | 菜单 / 权限点，树形（`parent_id`） |
| `bmrole_has_permissions` | 角色 ↔ 权限 |
| `bmrole_has_departments` | 角色 ↔ 部门 |
| `bmuser_has_roles` / `bmuser_has_jobs` | 账号 ↔ 角色 / 岗位 |
| `bmlog_login` / `bmlog_operate` | 登录日志 / 操作日志 |
| `bmoperation_logs` | 业务操作日志（telegram 模块建的表，记录 member 维度操作） |
| `bmpersonal_access_tokens` | Sanctum Token。**已改用显式短索引名**（原名 67 字符会超限） |

### 2.2 机器人管理（`telegram` 模块）

| 表 | 说明 |
|---|---|
| `bmbots` | 机器人。`api_token` 调 API 用、**`url_token` = webhook 的 secret_token（唯一）**、`webhook_url`、`owner_id`、`third_config_id` 默认上游 |
| `bmbot_groups` | 机器人所在的群。`chat_id` varchar + `bot_id` 唯一确定一群；`app_id` 非空表示真人通道（存 `telegram_api_users.app_id`） |
| `bmgroup_groups` | 群分组 |
| `bmgroup_configs` | 群配置（欢迎语、自动回复、客服等） |
| `bmgroup_admins` / `bmgroup_members` | 群管理员 / 群成员快照（按 `chat_id`，多机器人共用一群时会互相覆盖，见第 5 节） |

### 2.3 功能体系（`telegram` 模块）

| 表 | 说明 |
|---|---|
| `bmfeatures` | 功能定义。`driver` 执行器 key、`trigger` 触发方式、`config` 执行器配置（JSON）、`category` / `type` / `requestType` / `location` 老字段 |
| `bmfeature_commands` | 命令定义：`command` 命令名、`params` 参数 schema、`reply_template` 回复模板、`scope`（group/private）、`permission`（all/admin） |
| `bmfeatures_binds` | 功能绑定：`bot_id` + `chat_id` + `feature_id`。`chat_id` 为空 = 实体级默认（对任意群生效）；`config` 可覆盖功能配置；`third_config_id` 可指定上游 |
| `bmfeature_data` | 按作用域存的会话数据：`scope_type`（group/bot/user/global）+ `scope_id` + `data` |
| `bmfeature_logs` / `bmfeatures_logs` | 功能执行日志（前者按 feature_id+command，后者按 bot/chat） |

### 2.4 上游回调与交互（`telegram` 模块）

| 表 | 说明 |
|---|---|
| `bmfeature_hooks` | 回调入口。`token` 回调地址用（只生成一次）、`secret`/`sign_field`/`sign_algo`/`check_timestamp` 验签、`dedup_field` 幂等、`bot_id` 默认机器人、`route_field` 路由字段、`default_chat_id` 兜底群 |
| `bmfeature_hook_routes` | **回调路由表**：`route_value`（商户ID）→ `bot_id` + `chat_id`，可单独 `enabled` |
| `bminteractive_sessions` | 交互会话（按钮点击那套）。`code` 随机会话码、`buttons`、`allowed` 白名单、`payload`、`status`、`step`/`pending_act` 二次确认、`dedup_key`、`expires_at`、`attempts` |
| `bmmerchant_routes` | 商户路由（老机制）：`merchant_id` → `bot_id` + `chat_id` + `third_config_id` + `feature_id` |
| `bmthirdapi_config` | 三方接口配置（上游）：`api_url`、`token`、`secrept_key` 密钥、`channel_id` |
| `bmthird_api_endpoints` | 上游接口定义：`code`、`method`、`path_template`、`headers`、`query`、`params_schema`、`response_schema`。**由代码里的 `EndpointProvider` 同步生成** |
| `bmthird_config_endpoints` | 上游对接口的覆盖（按 `third_config_id` 覆盖 path/method/headers） |

### 2.5 消息与群发（`telegram` 模块）

| 表 | 说明 |
|---|---|
| `bmmessage_templates` | 消息模板：`title`、`blocks`（内容块）、`channel` |
| `bmmessage_sends` | 群发任务：`template_id`、`bot_id`、`chat_ids`、`blocks`、`total`/`success`/`failed`、`status` |
| `bmmessage_send_logs` | 群发逐群结果：`send_id`、`chat_id`、`status`、`error` |

### 2.6 真人账号采集（`telegram` 模块）

| 表 | 说明 |
|---|---|
| `bmtelegram_api_users` | 真人账号：`app_id`/`app_hash`、`phone_number`、`session_file`、`login_status`、`scan_count` |
| `bmservice_peoples` | 客服账号（`group_id` + `app_id`） |
| `bmphones` | 手机号池。含 `department_id`（按部门分配） |
| `bmemojis` | 表情包：`telegram_id`、`file_id`、`set_name`、`image_path`、`tags` |
| `bmscanlogs` | 扫描日志 |
| `bmmembers` | 终端用户（Telegram 用户维度） |

### 2.7 钱包 / 金融（`telegram` 模块）

| 表 | 说明 |
|---|---|
| `bmwallets` | 钱包：`member_id` + `currency`、`balance`、`frozen_balance`、累计充值/提现 |
| `bmledgers` | 账务流水：`type`、`amount`、`balance_before/after`、`related_type`/`related_id` |
| `bmrecharge_orders` | 充值订单：`order_no`、`bot_id`（发起该订单的机器人，通知靠它发回去）、`channel_id`、`idempotency_key`、`third_order_no`、`status`、`request_sign` |
| `bmwithdraw_orders` | 提现订单：`bot_id`（同上）、`exchange_rate`、`withdraw_method`、`withdraw_info` |
| `bmpayment_channels` | 支付渠道：`code`、`submit_api`/`query_api`、`secret_key`、费率与限额 |
| `bmrisk_control_rules` / `bmrisk_control_logs` | 风控规则 / 风控命中记录 |
| `bmtransaction_limits` | 交易限额（按 `type` + `level`） |
| `bmexchange_rates` | 汇率。**已改用显式短索引名** `uk_exchange_rate` |
| `bmnotifications` | 站内通知：`member_id`、`channel`、`send_status`、`read_status` |

### 2.8 其它模块与框架

| 表 | 说明 |
|---|---|
| `bmcms_*`（12 张） | CMS：`cms_posts`、`cms_category`、`cms_tags`、`cms_comment`、`cms_links`、`cms_options`、`cms_resources`、`cms_user` 等 |
| `bmsystem_dictionary` / `_values` | 数据字典 |
| `bmschemas` | 代码生成器的表结构快照 |
| `bmmigrations` | 迁移记录（模块迁移也写在这里） |
| `bmfailed_jobs` | 失败队列 |

---

## 3. 核心关联关系

### 3.1 机器人 → 群 → 功能 → 命令

```
bmbots ──1:N──> bmbot_groups          （chat_id + bot_id 唯一确定一群）
   │
   └──1:N──> bmfeatures_binds ──N:1──> bmfeatures
                  │  chat_id 为空 = 实体级默认，对任意群生效
                  │  config 可覆盖功能配置；third_config_id 可指定上游
                  │
                  └──> bmfeatures ──1:N──> bmfeature_commands   （命令定义）
                                └──1:N──> bmfeature_data        （按 scope 存的数据）
```

### 3.2 上游回调链路

```
bmfeature_hooks ──1:N──> bmfeature_hook_routes     （商户ID → 机器人 + 群）
      │  feature_id → bmfeatures（driver = hook.receive）
      │  bot_id → bmbots（决定用谁的 token 发）
      └── token 拼出回调地址 /api/hooks/{token}

bmfeatures_binds.third_config_id ──> bmthirdapi_config（上游：base_url + 密钥）
                                          └──1:N──> bmthird_api_endpoints（接口）
                                          └──1:N──> bmthird_config_endpoints（按上游覆盖）
```

### 3.3 交互按钮

```
bminteractive_sessions
   ├ feature_id → bmfeatures（点按钮的功能，driver = interactive.click）
   ├ bot_id → bmbots
   ├ chat_id → bmbot_groups.chat_id
   ├ third_config_id → bmthirdapi_config（提交上游用）
   └ code：按钮 callback_data 里只有 code + 动作码，业务数据全在 payload 里
```

### 3.4 账号与权限

```
bmusers ──N:N──> bmroles ──N:N──> bmpermissions
   │                └──N:N──> bmdepartments
   ├ department_id → bmdepartments（树形）
   └ creator_id   → bmusers.id     ← 数据隔离的核心，见第 4 节
```

---

## 4. `creator_id`：数据隔离怎么落的库

所有业务表都带 `creator_id`，隔离不靠 `department_id`，靠**归属链**：

```
可见范围 = 本人  ∪  本人创建的账号（users.creator_id 递归）  ∪  这些节点在 parent_id 下的子树
```

| 表 | 隔离方式 |
|---|---|
| `bmbots` / `bmbot_groups` / `bmwallets` / `bmmembers` / `bmphones` | `DataScope::visibleCreatorIds()`（归属链 + 角色 `data_range`） |
| `bmroles` / `bmdepartments` | `HierarchyScope`（归属链 + `parent_id` 子树） |
| `bmjobs` | 归属链（无 parent_id） |
| `bmusers` | 同上，另外超管 id 单独保护 |

- `creator_id = 0` = 无主。业务数据按 `catch.data_scope_include_unowned` 兜底可见；
  权限体系数据（角色/部门/岗位）**不兜底**
- 新建时由 `storeBy()` 按登录用户自动填；**`updateBy()` 会用当前操作人覆盖它**
  —— 上级改一下下级建的数据，归属就跑到上级名下了，改动前留意
- 存量数据用 `php artisan telegram:backfill-ownership` 回填

---

## 5. 建表 / 改表注意事项

1. **迁移写在模块里**：`modules/Telegram/database/migrations/`，执行
   `php artisan catch:migrate telegram --force`（production 必须 `--force`，否则被安全确认静默跳过）。
2. **跨模块依赖**：`telegram` 的迁移会查 `permissions` 表，安装顺序必须是
   `permissions → cms → system → telegram`。
3. **软删除字段**：新表要么 `$table->deletedAt()`，要么明确不加；`CatchModel` 的 `delete()`
   只置 `deleted_at`，查询默认带 `deleted_at = 0`。清理脚本要写 `DB::table()` 或 `forceDelete()`。
4. **索引名**：多列索引显式命名，算好 `{前缀}{表}_{列}_index` 的长度 ≤ 64。
5. **字段类型一致性**：`bot_groups.chat_id` 是 varchar、`features_binds.chat_id` 是 bigint，
   新表建议统一 varchar（chat_id 可能是 `-100xxxxxxxxxx`）。
6. **`bot_groups` / `group_admins` / `group_members` 目前不带 bot_id 维度的部分**
   （`group_admins` / `group_members` 按 `chat_id` 存），一个群挂多个机器人时会互相覆盖——
   当前部署约定"一个群一个机器人"，如要支持需先加 `bot_id`。

---

## 6. 常用查询片段

```sql
-- 各表数据量
SELECT table_name, table_rows FROM information_schema.tables
WHERE table_schema = 'bots' ORDER BY table_rows DESC;

-- 某张表的字段（含注释）
SELECT column_name, column_type, is_nullable, column_default, column_comment
FROM information_schema.columns
WHERE table_schema = 'bots' AND table_name = 'bmfeatures'
ORDER BY ordinal_position;

-- 查无主数据（creator_id = 0），隔离排查用
SELECT id, role_name FROM bmroles WHERE creator_id = 0;
SELECT id, department_name FROM bmdepartments WHERE creator_id = 0;

-- 重复的 url_token（会导致 webhook 认错机器人，必须唯一）
SELECT url_token, COUNT(*) c, GROUP_CONCAT(id) ids FROM bmbots
GROUP BY url_token HAVING c > 1;

-- 回调路由表里「群不属于机器人」的脏数据
SELECT r.id, r.route_value, r.bot_id, r.chat_id
FROM bmfeature_hook_routes r
LEFT JOIN bmbot_groups g ON g.chat_id = r.chat_id AND g.bot_id = r.bot_id
WHERE g.id IS NULL;

-- 最近的回调执行日志
SELECT * FROM bmfeature_logs ORDER BY id DESC LIMIT 20;
```

> 库名 / 前缀按实际环境替换（本地测试：库 `bots`，用户 `bots`，密码 `bots123456`）。
