# Telegram 模块开发文档

> 适用模块：`modules/Telegram`
> 相关文档：[部署](deployment.md) · [数据库说明](database.md)
> 最后更新：2026-10-16

本文面向要在这个项目上做二次开发的人：先讲清"功能（Features）"这套机制，
再讲上游回调怎么接、按钮交互怎么配、外部 API 怎么调，最后是新增一个功能的完整步骤。

---

## 1. 模块概览

`modules/Telegram` 是本项目的核心模块，目前同时承载三块业务（后续拆分见第 10 节）：

| 业务域 | 主要内容 |
|---|---|
| 机器人管理 | 机器人、群组、功能、命令、回调、群发、交互按钮 |
| 钱包 / 金融 | 钱包、充值、提现、账单、风控、支付渠道、汇率 |
| 真人账号采集 | Telethon（Python 服务）多会话登录、群同步、表情包 |

### 目录结构

```
modules/Telegram/
├── Console/Commands/      7 个 artisan 命令（见第 8 节）
├── Contracts/             接口：FeatureDriver / FeatureContext / FeatureResult /
│                          CallbackTransformer / CallbackMessage / EndpointProvider
├── database/migrations/   54 个迁移（表清单见 database.md）
├── Enums/                 枚举
├── Events/ + Listeners/   TelegramUpdateReceivedEvent → HandleTelegramUpdateListener
├── Http/
│   ├── Controllers/       后台 CRUD（Bots / Features / FeatureBinds / ThirdConfig…）
│   └── Controllers/Api/   对外接口（WebHook / Hook / Bots / TelegramApiUser / TelethonEvent）
├── Jobs/                  异步任务
├── Models/                39 个模型（统一 DataRange 数据范围）
├── Providers/             TelegramServiceProvider
├── routes/
│   ├── api.php            对外 API（/api/hooks / /api/bot / /api/tg / /api/telegram）
│   └── route.php          后台 CRUD（/api/telegram/...）
└── Services/
    ├── Bot/               BotApiFactory（统一建 Telegram Api 客户端）
    ├── Callback/          回调消息转换器（Transformers + Registry + Context）
    ├── Feature/           功能体系：DriverRegistry / FeatureExecutor / UpdateRouter /
    │                      FeatureCommandDispatcher / UpstreamCaller / TemplateRenderer
    │   └── Drivers/       驱动实现（含 Custom/Demo 示例）
    ├── InteractiveSessionService.php   交互会话（按钮点击那套）
    ├── MerchantRouteService.php        商户 → 群 路由
    └── ...
```

---

## 2. 核心机制：功能（Features）

整个业务都是"配功能"而不是写 if-else。一个功能 = **执行器（driver）+ 触发方式（trigger）+ 配置（config）**。

### 2.1 四张表的关系

```
features（功能定义）
   │  driver    → DriverRegistry 里扫描到的执行器类
   │  trigger   → command / message / callback_query / webhook / manual
   │  config    → 该执行器 configSchema 声明的配置（JSON）
   │
   ├──< feature_commands     命令定义：命令名、参数 schema、回复模板、scope、permission
   ├──< features_binds       绑定：功能 + bot_id + chat_id（chat_id 为空 = 实体级默认）
   │                            │  绑定可覆盖 config、可指定 third_config_id（上游）
   ├──< feature_data         按 scope 存的会话数据（模板里的 {{merchant_id}} 之类）
   └──< feature_hooks        三方推送回调入口（token / 验签 / 默认机器人 / 路由字段）
            └──< feature_hook_routes   路由表：商户ID → 机器人 + 群
```

### 2.2 执行器（Driver）

`DriverRegistry` 扫描 `Services/Feature/Drivers/` 目录，把每个 `FeatureDriver` 实现注册进去。
**新增一个执行器 = 在目录里加一个类**，后台「功能列表 → 新建」的执行器下拉自动出现，不用改前端。

一个驱动需要实现：

```php
class XxxDriver implements FeatureDriver
{
    public static function key(): string;          // 稳定标识，存 features.driver
    public static function label(): string;        // 后台下拉显示名
    public static function group(): string;        // 下拉分组
    public static function triggers(): array;      // ['command','webhook',...]
    public static function configSchema(): array;  // 后台自动渲染配置表单
    public function validate(array $config): array;// 保存前校验，返回错误信息数组
    public function execute(FeatureContext $context): FeatureResult;
}
```

常用内置驱动：`telegram.api`、`hook.receive`（接收三方推送）、`interactive.click`
（交互按钮点击）、`custom.*`（业务示例）。

### 2.3 触发方式（trigger）

| trigger | 由什么触发 |
|---|---|
| `command` | 群里发斜杠命令（命令名在 `feature_commands` 里配） |
| `message` | 普通消息（图片/语音/文件/位置…） |
| `callback_query` | 点 inline 按钮 |
| `webhook` | 上游 HTTP 回调（`/api/hooks/{token}`） |
| `manual` | 后台手动执行 |

### 2.4 配置的三级覆盖

```
功能默认 config（features.config）
  └ 绑定覆盖（features_binds.config，同一功能在不同机器人/群可以不同）
```

合并逻辑在 `FeatureExecutor::mergeConfig()`。例：A 机器人要打码、B 机器人不打码，
就两个绑定各选不同的"消息处理"转换器。

---

## 3. 一条消息的完整链路

```
Telegram
  │ POST /api/webhook/pull（header x-telegram-bot-api-secret-token = bots.url_token）
  ▼
WebHookController::handle
  │ 1. 按 secret_token 查 bots → 唯一确定 $bot（查不到直接返回，不认错机器人）
  │ 2. 幂等锁（update_id + bot_id）、队列投递
  ▼
TelegramUpdateReceivedEvent → HandleTelegramUpdateListener
  │ 兜底同步群信息（BotGroups 按 chat_id + bot_id）
  ▼
UpdateRouter::route()
  ├─ 群成员事件        → ListenBotInGroupService
  ├─ 纯闲聊文本        → 丢弃（唯一允许过滤的情况）
  ├─ 斜杠命令          → FeatureCommandDispatcher（feature_commands → FeatureExecutor）
  └─ 其它全部          → routeToFeatures()：按 bot_id + chat_id + trigger 找绑定并执行
                            ▼
                       FeatureExecutor::execute()
                         ├ 绑定校验（command 触发强制要求「本 bot + 本群」有绑定）
                         ├ mergeConfig() 合并配置、按 scope 取 feature_data
                         ├ DriverRegistry 解析驱动 → validate
                         └ driver->execute(FeatureContext) → FeatureResult
                              ├ meta.answer_callback  → answerCallbackQuery
                              ├ meta.edit_message      → editMessageText / 撤按钮
                              └ meta.reply_markup      → sendMessage 带按钮
```

**关键约定**：

- 回复一律用 `BotApiFactory::forBot($bot)`，即**该机器人自己的 token**，发到来源 `chat_id`
- `webhook` 触发不回来源会话（本来就没有会话），由驱动自己指定目标群
- 分发、命令执行都带 `bot_id` / `chat_id` 双重限定，不会串机器人

---

## 4. 上游回调（三方推送）

### 4.1 给上游的接入说明（可直接转发这段）

**回调地址**

```
POST https://你的域名/api/hooks/{token}
```

`{token}` 在后台「回调设置」里生成，**只在首次创建时生成，之后不变**（上游配好了就不能换）。

**鉴权**：请求头带 `x-telegram-bot-api-secret-token`，值 = 该机器人 `bots.url_token`。
平台据此确定"用哪个机器人、哪个功能"，对不上直接返回，不会认错机器人。

**验签（可选）**：在「回调设置 → 验签」填密钥后启用，标准规则：

1. 去掉 `sign` 字段本身
2. 去掉值为 `null` / `''` / `[]` 的字段
3. 剩下按 key 升序（`ksort`）
4. `k=v` 用 `&` 连接，数组/对象用 `json_encode`（不带转义斜杠与 unicode）
5. `sign = strtoupper(HMAC_SHA256(待签串, 密钥))`，老上游可用 `strtoupper(MD5(待签串 + "&key=" + 密钥))`

**幂等 / 防重放**

- `dedup_field`：相同字段值的重复推送直接返回成功（键只在**执行成功后**才占住，失败可重试）
- `check_timestamp`：**需先配置验签密钥才生效**。开启后校验 `timestamp` 偏差
  （窗口取 `config/hook.php` 的 `sign_ttl`，默认 300 秒），同时对 `nonce` 去重（窗口 2 倍）
- 时间戳过期 / nonce 重放统一返回 `{"status":"success","message":"duplicate ignored"}`，上游无需重试

**应答格式固定**：`{"status":"success"}` / `{"status":"error","message":"..."}`（HTTP 200 / 4xx、5xx）

### 4.2 路由：商户ID → 机器人 + 群

多机器人下必须同时定"用哪个机器人发"和"发到哪个群"，否则会串。配置三处：

| 位置 | 字段 | 作用 |
|---|---|---|
| `feature_hooks.bot_id` | 默认机器人 | 没命中路由表时用哪个机器人发 |
| `feature_hooks.route_field` | 路由字段 | 从报文哪个字段取路由键，支持 `data.merchant_id` |
| `feature_hooks.default_chat_id` | 兜底群 | 未命中路由表时发到这个群 |
| `feature_hook_routes` | 路由表 | 每行：商户ID + 机器人 + 群，可单独停用 |

**解析顺序**：

```
路由键 = data_get(报文, route_field)
  → 命中路由表        → 那行的 bot_id + chat_id
  → 未命中            → 回调入口的默认机器人 + 兜底群
  → 都没配            → 老数据兼容：该功能只绑定了一个机器人才推断，绑了多个直接 422（不猜）
```

**硬校验**：发送前校验 `(bot_id, chat_id)` 在 `bot_groups` 里确实存在这一对；
群已经记在别的机器人名下一律拦截（返回 422 并记日志）——这是防串的最后一道闸。

### 4.3 消息处理：转换器（Transformer）

"报文 → 群消息"这一段由转换器决定，**同一个功能在不同绑定上可以选不同转换器**
（`features.config.transformer` 功能默认，`features_binds.config.transformer` 绑定覆盖）。

内置三个：

| key | 说明 |
|---|---|
| `default` | 按功能配置的「消息模板」渲染（`{{字段}}` / `{{_raw}}` 整段原文） |
| `mask` | 手机号/银行卡/邮箱/身份证中段替换为 `*` |
| `trade_confirm` | 文本 + 「确认 / 驳回」按钮（走交互会话） |

转换器是**全局代码资产**：`TransformerRegistry` 扫描 `config/transformers.php` 配的目录，
不做跨请求缓存——**新增/删除一个类，后台重新打开功能就能看到**；正在用的类被删了，
自动回退到 `default` 并在后台提示「已失效」。

**自己写一个**：

```php
// modules/Telegram/Services/Callback/Transformers/MaskedNotifyTransformer.php
class MaskedNotifyTransformer implements CallbackTransformer
{
    public static function key(): string         { return 'mask'; }
    public static function label(): string       { return '隐藏关键信息'; }
    public static function description(): string { return '手机号/银行卡/邮箱/身份证中段替换为 *'; }

    public function transform(TransformerContext $context): CallbackMessage
    {
        return CallbackMessage::text($this->mask($context->render()));
    }
}
```

`TransformerContext` 提供：`payload`（原文）、`config()`（合并后配置）、`render()`（模板渲染）、
`sessionButtons()`（建交互会话，返回可直接用的按钮）。**不提供发送能力**——
"用哪个机器人、发到哪个群"必须由系统决定，不能交给业务代码。

`CallbackMessage` 支持文本 + 按钮：

```php
CallbackMessage::text($text, $parseMode)
    ->withButtons($buttons);        // 一维自动排一行，二维按行排
```

`callback_data` 超过 Telegram 的 64 字节会自动丢弃并记日志（不会让整条消息发不出去）。

### 4.4 上游侧参考实现

**PHP**

```php
function buildString(array $params): string
{
    unset($params['sign']);
    $params = array_filter($params, fn ($v) => $v !== null && $v !== '' && $v !== []);
    ksort($params);
    return urldecode(http_build_query($params)); // 别做 urlencode，两边要一致
}

function sign(array $params, string $secret): string
{
    return strtoupper(hash_hmac('sha256', buildString($params), $secret));
}

$payload = [
    'merchant_id' => 'M8888',
    'trade_no'    => 'D10001',
    'amount'      => '199.00',
    'timestamp'   => (string) time(),
    'nonce'       => bin2hex(random_bytes(8)),
];
$payload['sign'] = sign($payload, '你我约定的密钥');
```

**Go**

```go
func buildString(p map[string]string) string {
    delete(p, "sign")
    keys := make([]string, 0, len(p))
    for k, v := range p {
        if v != "" {
            keys = append(keys, k)
        }
    }
    sort.Strings(keys)
    parts := make([]string, 0, len(keys))
    for _, k := range keys {
        parts = append(parts, k+"="+p[k])
    }
    return strings.Join(parts, "&")
}

func sign(p map[string]string, secret string) string {
    mac := hmac.New(sha256.New, []byte(secret))
    mac.Write([]byte(buildString(p)))
    return strings.ToUpper(hex.EncodeToString(mac.Sum(nil)))
}
```

**自测 curl**

```bash
SECRET='你我约定的密钥'
TS=$(date +%s)
NONCE=$(head -c 8 /dev/urandom | xxd -p)
STR="merchant_id=M8888&nonce=$NONCE&timestamp=$TS&trade_no=D10001"
SIGN=$(printf '%s' "$STR" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $2}' | tr 'a-f' 'A-F')

curl -X POST "https://你的域名/api/hooks/你的token" \
  -d "merchant_id=M8888" -d "trade_no=D10001" -d "amount=199.00" \
  -d "timestamp=$TS" -d "nonce=$NONCE" -d "sign=$SIGN"
```

---

## 5. 按钮交互（群里点按钮）

分两步，由两个功能配合完成：

**第一步：发带按钮的消息** —— 回调功能 + `trade_confirm` 转换器（见 4.3），
或自定义驱动里调 `$context->sessionButtons()`。

**第二步：处理点击** —— 建一个功能，执行器选「交互按钮点击处理」（`interactive.click`），
触发方式 `callback_query`，**绑定到同一个机器人 + 同一个群**，然后配 `branches`：

```json
{
  "confirm": {
    "mode": "submit",
    "endpoint_code": "merchant.trade.submit",
    "method": "POST",
    "sign": true,
    "sign_algo": "hmac_sha256",
    "params": {
      "merchant_id": "{{session.merchant_id}}",
      "trade_no": "{{payload.trade_no}}",
      "action": "confirm"
    },
    "success_template": "✅ 已提交：{{payload.trade_no}}",
    "fail_template": "❌ 提交失败：{{error}}",
    "confirm": {
      "text": "确认提交？",
      "buttons": [{"text": "确认", "act": "yes"}, {"text": "取消", "act": "no"}]
    }
  },
  "reject": {"mode": "cancel", "reply_template": "已驳回 {{payload.trade_no}}"}
}
```

- `mode`：`submit`（调上游，需 `endpoint_code`）/ `reply`（只回复）/ `cancel`（作废）
- 模板变量：`{{payload.xxx}}` `{{session.code}}` `{{session.merchant_id}}` `{{merchant_id}}`
  `{{trade_no}}` `{{act}}` `{{operator.id}}` `{{operator.username}}` `{{response.xxx}}` `{{error}}`
- `confirm` 是二次确认：第一次点只把消息改成确认界面，再点「确认」才提交

**提交给哪个上游**：会话上的 `third_config_id` → 功能绑定上的 → **机器人上的**，取第一个有值。
一般配在机器人上即可（A 商户的机器人提交到甲上游，B 商户的提交到乙上游）。

**安全校验顺序**（`InteractiveClickDriver`，顺序不要改）：

1. `code` 能查到会话（按钮里只有随机 code，猜不到）
2. 会话 `chat_id` 与当前群一致（防按钮被转发到别的群点）
3. 会话 `bot_id` 与当前机器人一致（多 bot 防串）
4. 未过期（默认 24h）
5. 操作人在白名单（默认群管理员，`/trsq` 可指定）
6. 动作码在本次会话的按钮范围内（防自己拼 callback_data）
7. `claim()` 原子抢锁（并发点 / Telegram 重投只提交一次）

---

## 6. 外部 API（供第三方系统调用）

路由定义在 `modules/Telegram/routes/api.php`，**都在 `validate.apikey` 中间件下**
（请求头带平台 Api-Key，需与 `.env` 的 `EXTERNAL_API_KEY` 一致），并有 `throttle:20,1` 限流。

| 路由 | 用途 |
|---|---|
| `POST /api/bot/send/group` | 群发：`{botId, groupId \| chatIds \| 'all', message, parseMode, topicId, messageThreadId}` |
| `POST /api/tg/user/operate/{type}/feature` | 操作真人账号功能（`type` = `check` \| `feature`） |
| `GET  /api/tg/user/feature/list` | 真人账号可用功能列表 |
| `POST /api/telegram/events/emoji` | Python 服务回调：表情事件 |
| `POST /api/telegram/events/member` | Python 服务回调：群成员事件 |
| `POST /api/webhook/pull` | Telegram webhook 入口（无 apikey，靠 secret_token） |
| `POST /api/hooks/{token}` | 上游业务回调入口（无 apikey，靠 url_token + 验签） |

**群发的归属校验**：`chatIds` 会与该机器人 `bot_groups` 里的群取交集，
不属于这个机器人的群会被过滤并记日志（防止拿 A 的机器人往 B 的群发）。

**真人账号功能**：`type=feature` 时按 `telegram_api_users.app_id` 解析账号。
真人类功能就是普通的驱动，放在 `Services/Feature/Drivers/Custom/RealMan/` 下
（`AbstractRealManFeature` 是基类），由 `DriverRegistry` 统一扫描，模块安装时由
`Installer.php` 写入功能列表。

---

## 7. 数据隔离（部门隔离）

权限体系数据（角色 / 部门 / 岗位 / 账号）按 **`creator_id` 归属链 + `parent_id` 子树** 隔离：

```
可见 = 本人创建的  ∪  本人创建的账号（按 users.creator_id 递归）创建的
       ∪  上述节点的整棵 parent_id 子树
```

方向**单向向下**：上级看得到下级建的，下级看不到上级建的。
「被分配 / 被授予」不等于「归他管」——超管把部门/角色分配给管理员，管理员看不到那些部门/角色。

| 口径 | 用在哪 |
|---|---|
| `DataScope::ownershipCreatorIds()` / `inOwnershipChain()` / `assertInOwnershipChain()` / `applyOwnership()` | 权限体系数据（角色/部门/岗位/账号、功能的转换器配置） |
| `DataScope::visibleCreatorIds()`（会叠加角色 `data_range`） | 业务数据（bots / bot_groups / members / wallets…） |
| `HierarchyScope::visibleIds()` | 树形数据（角色 / 部门）的归属链 + 子树 |

`creator_id = 0` 的"无主"数据：业务数据按 `catch.data_scope_include_unowned` 兜底可见；
**权限体系数据不兜底**（否则管理员又能看到一堆不知道谁建的角色）。

**排查命令**：

```bash
php artisan permissions:check-data-scope <用户名或ID>
```

会打印归属链、可见角色/部门/岗位、每条记录的归属人，以及**被隔离掉**的记录——
一眼看出是配置问题还是真泄漏。

**存量数据回填**：

```bash
php artisan telegram:backfill-ownership          # 预览
php artisan telegram:backfill-ownership --apply  # 执行
```

---

## 8. 常用命令

| 命令 | 用途 |
|---|---|
| `php artisan catch:migrate telegram --force` | 跑 telegram 模块迁移（production 必须 `--force`） |
| `php artisan telegram:sync-features` | 扫描功能代码并同步到功能列表 |
| `php artisan telegram:warm-feature-cache` | 预热功能数据缓存（`optimize` 后建议执行） |
| `php artisan telegram:reset-features` | 重置功能列表为标准定义 |
| `php artisan telegram:cleanup-legacy-features` | 清理老 schema 遗留功能（默认只预览） |
| `php artisan telegram:backfill-ownership` | 回填业务数据 creator_id |
| `php artisan telegram:webhook` | 批量设置 / 移除 webhook |
| `php artisan permissions:check-data-scope <用户>` | 数据隔离自查 |
| `php artisan catch:route:list` | 查看全部路由 |

---

## 9. 新增一个功能的完整步骤

### 9.1 只配不写代码（推荐先试）

1. 功能列表 → 新建：选执行器（如 `hook.receive`）、触发方式
2. 填执行器配置（`configSchema` 自动渲染表单）
3. 绑定到机器人 + 群（需要"在群里生效"的功能都要绑）
4. 回调类功能：在「回调设置」里配默认机器人、路由字段、路由表、消息处理转换器

### 9.2 写自己的执行器

复制 `Services/Feature/Drivers/Custom/Demo/` 下的示例改名，改三处：

| 改什么 | 文件 |
|---|---|
| 签名规则 | `TradeSigner.php`（换算法只改这一个文件） |
| 取字段 | 两个 `handle()`（回调侧 + 点击侧） |
| 配置项 | `configSchema()` |

如果你的上游就是标准签名规则，**这两个类都不用写**：后台把密钥填进 hook 的「验签密钥」，
执行器选通用的 `hook.receive` + `interactive.click` 即可。

### 9.3 声明自己要用的上游接口

不要往 `PlatformEndpointRegistry` 里加大数组——**谁用接口谁声明**：
在 Driver / 功能类上实现 `EndpointProvider`，返回 `endpoints()`，扫描器会自动汇总到后台下拉。

```php
class TradeConfirm extends BaseCustomFeature implements EndpointProvider
{
    public static function endpoints(): array
    {
        return [
            'merchant.trade.submit' => [
                'name' => '提交交易处理结果',
                'method' => 'POST',
                'path_template' => 'api/merchant/trade/submit',
                'params_schema' => [['name' => 'trade_no', 'required' => true, 'desc' => '单号', 'map_to' => 'tradeNo']],
                'response_schema' => ['success' => '是否成功'],
                'remark' => '…',
            ],
        ];
    }
}
```

### 9.4 自己写转换器

往 `Services/Callback/Transformers/` 丢一个类（见 4.3），或先改
`config/transformers.php` 的 `paths` 指向自己的目录。

---

## 10. 已知问题与后续

| 项 | 说明 |
|---|---|
| 模块职责过重 | `Telegram` 同时含钱包金融、真人采集两块，理想上应拆成独立模块（`Wallet` / `Collect`），改动大，暂缓 |
| 按钮点击两套并存 | `Custom/Demo/TradeConfirm`（硬编码）与 `interactive.click`（配置驱动）功能重复，新功能建议用后者 |
| `bot_groups.chat_id` 是 varchar、`features_binds.chat_id` 是 bigint | 两处类型不一致，跨表关联时注意隐式转换 |
| `features_binds.bot_id` 语义重载 | 真人通道里这个字段存的是 `telegram_api_users.app_id`，不是机器人 ID |
| `config/auth.php` 的 `providers.users.model` 仍指向 `App\Models\User` | 实际业务模型是 `Modules\User\Models\User`，靠 CatchAdmin 运行时覆盖，属隐患 |
| 拼写遗留 | `ThridConfigController`、`ThirdApiConfig.secrept_key`、`Scanlogs` / `SCanLog` 等，改动涉及库字段，需单独评估 |
| 索引名长度 | 表前缀较长时 Laravel 自动生成的索引名可能超 64 字符，新增多列索引务必显式命名 |

---

## 11. 排错

| 现象 | 排查 |
|---|---|
| 点按钮群里没反应 | 点击功能（`interactive.click`）没绑定到这个机器人 + 这个群；或回调前缀不匹配（非自家按钮静默忽略） |
| 回调返回 422「未配置回调目标」 | 「回调设置」里没配默认机器人，且该功能绑定了多个机器人无法推断 |
| 回调返回 422「目标群不属于该机器人」 | 路由表里这一行的群不属于所选机器人；确认群已同步（`bot_groups` 有记录）且机器人确实在群里 |
| 回调成功但群里没消息 | 看 `feature_logs`；转换器是否返回空文本；机器人是否已被踢出群 |
| 消息发到了别的机器人群 | 立即查 `feature_hook_routes` 与 `feature_hooks.bot_id`；系统有 `(bot_id, chat_id)` 硬校验，出现这种情况基本是历史脏数据 |
| 后台看不到某模块菜单 | 模块没装：`php artisan app:module:install {module}` |
| 改了代码但下拉没变化 | 转换器/驱动都是**实时扫描**，无需清缓存；若用了 `optimize`，跑 `telegram:warm-feature-cache` |
