# 功能与插件开发指南

> 给「想给机器人加一个新功能」的开发者看的单一入口：内部开发功能、外部开发插件，两条路的选型、完整流程、调试方法与常见坑。
> 核心机制（四张表、执行器、触发方式、配置覆盖）见 [Telegram 模块开发文档](telegram-module.md)，本文不重复。

最后更新：2026-11-10

---

## 0. 先选路：内部功能 or 插件？

同一个基类（`BaseCustomFeature`）、同一套后台（功能列表、绑定、命令、计费），差别只在**代码放哪、怎么进系统**：

| | 路线 A：内部功能 | 路线 B：插件 |
|---|---|---|
| 代码位置 | `modules/Telegram/Services/Feature/Drivers/Custom/` | `modules/Telegram/Plugins/{Studly(slug)}/`（上传后落盘） |
| 怎么进系统 | 随代码发布，`telegram:sync-features` 扫描发现 | 后台「插件管理」上传 zip，过两道审核后安装 |
| 要不要审核 | 不要 | 要（脚本审核必过；AI 审核后台开关控制，可强制） |
| 能否改核心 / 连内部服务 | 能（整个 Laravel 都可用） | 不能（审核拦截 eval / SQL / 内网地址等，只能用平台封装的能力） |
| 版本归属 | 项目版本，所有部署一起更新 | 独立于项目版本，哪个部署想要传哪个 |
| 适合 | 自己团队、要改平台行为、要用内部模型 | 三方开发者、不想交出源码库、按部署单独分发 |

**选型口诀**：自己人写、要碰内部代码 → 路线 A；给别人用、不能碰核心、想要独立分发 → 路线 B。
两条路写的功能类**完全同构**——先在内部写原型，验证完想分发时搬进插件目录改个命名空间就能发布。

---

## 1. 共同的地基：一个类就是一个功能

无论哪条路，功能 = 一个继承 `BaseCustomFeature` 的类。基类位置：
`modules/Telegram/Services/Feature/Drivers/BaseCustomFeature.php`（类头注释有一个完整示例）。

### 1.1 必须实现的只有两个

```php
public static function featureName(): string;                 // 后台「功能列表」显示名
public function handle(FeatureContext $ctx): FeatureResult;   // 命令命中后的业务逻辑
```

### 1.2 常用可选声明（都是静态方法）

| 方法 | 作用 | 不写的默认值 |
| --- | --- | --- |
| `key()` | 执行器标识，写进 `features.driver` | 类名（内部功能习惯 `custom.xxx`，插件习惯 `plugin.slug`） |
| `uuid()` | 功能身份证，入库为 `custom:{uuid}`，后台所有配置都挂在这行上 | 短类名 |
| `commands()` | 斜杠命令：`command / usage / description / params` | 无命令（只能手动/API 触发） |
| `configSchema()` | 后台配置表单（前端自动渲染） | 无配置项 |
| `defaultConfig()` | 首次同步写入 `features.config` 的默认值 | 空 |
| `group()` | 后台分组名 | `自定义` |
| `accessLevel()` | `'free'` / `'paid'`，仅是**首次同步默认值**，之后以后台为准 | `free` |
| `featureDescription()` | 功能描述 | 空 |
| `endpoints()` | 声明要用的上游接口（实现 `EndpointProvider`，见第 4 节） | 不调上游 |

> **uuid() 是最要紧的一个**：改名类/挪命名空间后默认值会变，等于换了个新功能，后台配置全留在旧行上。
> 重构时把 `uuid()` 显式写回原值即可接回。详见基类 `uuid()` 的注释。

### 1.3 handle() 拿什么、还什么

入参 `FeatureContext`（`modules/Telegram/Contracts/FeatureContext.php`）：

| 代码 | 含义 |
| --- | --- |
| `$ctx->args` | 命令实参数组（按位置） |
| `$ctx->value('name')` | 按参数名取值：**实参优先，其次该作用域已存数据** |
| `$ctx->config('greeting')` | 读后台配置（`configSchema` 声明的字段，支持 `a.b` 路径） |
| `$ctx->stored` | 本作用域已持久化的数据 |
| `$ctx->payload(...)` | 触发载荷：按钮 `callbackData()`、消息 `mediaType()`、`messageId()` 等 |
| `$ctx->bot / $ctx->chatId / $ctx->userId` | 当前机器人、会话、触发者 |

返回 `FeatureResult`，用基类便捷方法构造：

```php
return $this->reply('文本');          // 回复当前会话
return $this->fail('原因');           // 失败（记入 feature_logs）
return $this->ok();                   // 成功但不回复
```

持久化用 `$this->putData($ctx, ['k' => 'v'])` / `$this->data($ctx)` / `$this->forgetData($ctx)`，
作用域默认是**群**（`scope_type` 可配为 `bot / user / global`）——「绑定商户号 `/bd 123` 之后查余额不用再带号」就是这么实现的。

### 1.4 最小骨架

```php
class SayHi extends BaseCustomFeature
{
    public static function featureName(): string { return '打招呼'; }

    public static function commands(): array
    {
        return [[
            'command'     => 'hi',
            'usage'       => '/hi 名字',
            'description' => '向某人问好',
            'params'      => [['name' => 'name', 'required' => true, 'hint' => '名字']],
        ]];
    }

    public function handle(FeatureContext $ctx): FeatureResult
    {
        $name = trim((string) $ctx->value('name', ''));

        return $name === ''
            ? $this->fail('用法：/hi 名字')
            : $this->reply("你好，{$name}");
    }
}
```

---

## 2. 路线 A：内部开发（随代码发布）

### 2.1 目录与流程

```
modules/Telegram/Services/Feature/Drivers/Custom/
  Merchant/   ← 商户类（绑定、余额、订单…）
  Wallet/     ← 钱包类（充值、提现、账单…）
  RealMan/    ← 真人号类
  Ocr/        ← 识图类
  Demo/       ← 回调演示
```

1. 复制一个最接近的内置功能（推荐 `Merchant/GetMerchantBalance.php`，头注释是完整的改造示范），
   改类名和逻辑；或直接照第 1.4 节骨架从零写。
2. `php artisan telegram:sync-features` —— 功能入库（幂等，重复跑不产生脏数据）。
3. 后台「功能列表」里出现该功能 → 配置命令、绑定机器人/群 → 群里试。
4. 提交代码。功能随项目版本走，`install.sh --update` / `deploy.sh` 部署后自动生效。

### 2.2 不写代码也能先验证想法

后台「功能列表 → 新建」可以**纯配置**出一个 HTTP 转发功能（选执行器、填接口和模板），
不需要写 PHP。适合先跑通链路、再决定要不要落成代码。见 `telegram-module.md` §9.1。

### 2.3 本地调试：不经 Telegram 直接执行

群链路要等真实消息，单测一个功能用 tinker 直调执行器：

```bash
php artisan tinker
```

```php
$f = \Modules\Telegram\Models\Features::query()->where('feature', 'custom:SayHi')->first();

// params 与 commands() 里声明的 params 对齐，value('name') 才能按名取到实参
app(\Modules\Telegram\Services\Feature\FeatureExecutor::class)
    ->execute($f, ['args' => ['小明'], 'params' => [['name' => 'name']]], null, null, 123)
    ->message;
```

- 第 3 个参数传 `Bots`、第 5 个传 `chatId`，需要上下文的功能（读绑定、按群存数据）要给真值。
- 执行失败（含 paid 拦截、`_error`）都会写 `feature_logs` 表，`fail()` 的原因在里面。
- 改了 `commands()` / `configSchema()` 重新 `sync-features` 即可；改代码不需要（每次执行都加载最新类）。

---

## 3. 路线 B：插件开发（不动核心，上传安装）

### 3.1 从模板与示例起步

拿一份现成的：

| 材料 | 下载站 | 源码位置 | 内容 |
| --- | --- | --- | --- |
| 最小模板 `demo_hello` | `/downloads/plugin-template.zip` | `resources/plugin-template/` | 一个命令：参数、持久化、后台配置、失败提示 |
| 商户三件套 `merchant_demo` | `/downloads/plugin-example-merchant-demo.zip` | `resources/plugin-examples/merchant-demo/` | **内部版商户功能的插件对照实现**：`/bmd` 绑定、`/ymd` 查余额、`/cxm` 查订单 |

`merchant_demo` 与内部示例 `Drivers/Custom/Merchant/`（`BindMerchant` / `GetMerchantBalance` /
`QueryOrder`）功能同构，类头注释里有一张逐项差异表（命名空间、代码组织、商户号存储、
接口 code 前缀）——两边对照着看，就知道一个功能「从内部搬成插件」要动哪里。

```
demo_hello/
  manifest.json      # slug / name / version / author / description
  HelloFeature.php   # 功能类（就是第 1 节那个类）
  README.md          # 可选
```

### 3.2 三条硬规则（审核会拦）

1. **命名空间 = `Modules\Telegram\Plugins\{Studly(slug)}`**，且目录名一致：
   `slug = demo_hello` → 命名空间 `Modules\Telegram\Plugins\DemoHello`、目录 `DemoHello`。
   zip 顶层文件夹名随意，安装器按 slug 重新落盘。
2. **只允许 `.php / .json / .md / .txt`**，单文件 ≤ 512KB，解压 ≤ 5MB，≤ 200 文件，拒绝 `../` 与绝对路径。
3. **禁止**：`eval` / 系统命令 / 动态 `include $x` / 变量变量；读 `.env`、`/etc/passwd`、核心文件；
   访问本机与内网地址；裸 SQL、改表结构、`Artisan::call`、注册路由；命名空间冒充核心前缀。

脚本审核有 error 就**不落盘**，且不可强制绕过；完整规则见
`modules/Telegram/Services/Plugin/PluginAuditor.php` 的 `rules()`。

### 3.3 打包、上传、审核

```bash
cd demo_hello
zip -r demo_hello.zip .
```

后台「插件管理 → 上传插件」：

1. **脚本审核**：语法（`token_get_all` 解析级）、危险函数、敏感路径、命名空间——错误即拒；
2. **AI 审核**：后台「审核设置」打开开关才参与（OpenAI 兼容接口）；被拒时会弹确认，
   看懂代码后可以**强制安装**（只 AI 结论可推翻）；
3. 通过后落盘到 `modules/Telegram/Plugins/DemoHello/`，功能**自动同步进「功能列表」**——
   无需重启、无需 dump-autoload（插件目录正好落在 PSR-4 的 `Modules\` 下）。
4. 然后和内部功能完全一样：后台绑定机器人/群、配命令，群里 `/hello 小明` 试。

审核被拒的包不会留任何文件，但「审核未通过」记录里能看到**文件、行号、原因**，改完再传。

### 3.4 更新一个已安装的插件

当前版本的规则：**slug 已存在（含已安装）时再上传会被拒**——「请先卸载旧版本」。
而卸载会删除该插件同步出的功能与绑定。所以更新插件的正确姿势：

1. 先在后台**记下**该功能的绑定与配置（截图或抄下来）；
2. 卸载旧版 → 上传新版 zip → 重新绑定。

> 这个「更新丢配置」是已知限制（卸载物理删除是为了绕开 slug 唯一索引与软删除的冲突），
> 如果常用插件升级频繁，值得加「同名覆盖升级」能力。

### 3.5 插件里能用什么

- 第 1 节的全部声明与 `FeatureContext` / `FeatureResult` API——**与内部功能完全一致**；
- 平台封装：`$this->callEndpoint()`（调上游）、`$this->render()`（模板）、`putData/data`（存储）；
- `LocalizedReply` 之类内部 trait 也在 `Modules\Telegram\...` 命名空间下，`use` 即可用。

**不要**在插件里直连上游 HTTP 或自己连库——接口规范、鉴权、响应归一、数据隔离都在平台侧。

---

## 4. 要调上游接口：两条路都看这节

### 4.1 声明接口 + 调用

```php
class Xxx extends BaseCustomFeature implements EndpointProvider
{
    public static function endpoints(): array
    {
        return [
            'merchant.balance' => [            // 平台 code，全局唯一
                'name' => '查询商户余额',
                'method' => 'GET',
                'path_template' => 'api/merchant/balance',
                'params_schema' => [['name' => 'merchant_id', 'required' => true]],
                'response_schema' => ['balance' => '余额', 'currency' => '币种'],  // 平台标准字段
            ],
        ];
    }

    public function handle(FeatureContext $ctx): FeatureResult
    {
        $data = $this->callEndpoint('merchant.balance', ['merchant_id' => '123'], $ctx);

        if (isset($data['_error'])) {
            return $this->fail('查询失败：' . $data['_error']);
        }

        return $this->reply($this->render('余额：{{data.balance}} {{data.currency}}', $data, $ctx));
    }
}
```

要点：

- **功能代码只认平台标准字段**（`response_schema` 声明的），不猜厂商字段名；
  各家上游的字段差异写在它自己的**上游适配器插件**里（见 [upstream-adapter.md](upstream-adapter.md)），
  换上游时功能一行不改。
- 上游是谁由「机器人 → 可绑定上游」决定（每个部署/用户各自配置），不在代码里写死。
- 上游返回格式太怪、适配器也表达不了时，功能可以自带解构：覆写
  `responseHandlers()`（`endpointCode => 回调`），优先于适配器生效，见基类注释。

### 4.2 不需要上游的功能

纯 Telegram 行为（群发、踢人、识图、本地查询）不用实现 `EndpointProvider`。
`needsUpstream()` 默认由 `endpoints()` 是否为空推导，这类功能在后台不允许绑定上游——是防呆，不是 bug。

---

## 5. 计费与有效期

- `accessLevel()` 返回 `'paid'`：执行时校验归属账号是否在有效套餐期内（`FeatureExecutor` 统一拦截，
  插件绕不过）；`'free'` 永久可用。
- 代码声明只是**首次同步的默认值**，之后在后台「功能列表」改，重复同步不会覆盖。
- 套餐购买、调账见 [user-guide.md](user-guide.md) 的「套餐管理」章节。

---

## 6. 常见坑速查

| 症状 | 原因 | 处理 |
| --- | --- | --- |
| 后台看不到新功能 | 没跑同步 / 注册表没扫到 | `php artisan telegram:sync-features`；插件检查命名空间与目录名 |
| 重构后配置全丢 | 改了类名，`uuid()` 默认值跟着变 | 把 `uuid()` 显式写回原值 |
| 安装插件报「功能标识冲突」 | `uuid()`（默认短类名）撞了已有功能 | 改 `uuid()` 后重新打包 |
| 安装插件报「命令已被其它功能占用」 | `commands()` 里的命令名与已有功能重复（命令名全局唯一） | 换命令名后重新打包；不报错时才会静默失效 |
| 一个类多个命令在 tinker 直调返回「未知命令」 | 手动执行没传命令名 | `options` 里加 `'command' => 'bmd'`（群内链路自动注入，无需处理） |
| 安装插件报命名空间错误 | `slug` 与命名空间/目录名没对上 | `demo_hello` → `Modules\Telegram\Plugins\DemoHello` |
| 插件装完群没反应 | 功能没绑定到机器人/群 | 「功能列表」里绑定；命令是否被别的功能占用 |
| `/api` 请求成功但回复空白 | 模板字段名与 `response_schema` 不一致 | 只用 `response_schema` 声明的标准字段；留空失败时明确 `fail()` |
| 更新插件被拒 | slug 已存在 | 先卸载再装（注意 §3.4，绑定要重配） |
| AI 审核总是拒绝 | 模型接口不通 / 误判 | 接口不通是 fail-closed 拒绝；误判可确认后强制安装 |
| 付费功能群内提示需要套餐 | `accessLevel()` = paid 且账号过期 | 后台改计费级别，或给账号购套餐/调账 |

---

## 7. 速查

```bash
# 同步功能（内部新功能、插件安装后都会用到；幂等可重复跑）
php artisan telegram:sync-features

# tinker 直调一个功能（见 §2.3）
php artisan tinker

# 排错：执行日志
#   表 feature_logs（fail() 与拦截原因都在这里）

# 插件模板
#   官网下载站 /downloads/plugin-template.zip
#   源码：resources/plugin-template/

# 相关文档
#   核心机制        docs/telegram-module.md
#   上游适配器      docs/upstream-adapter.md
#   后台操作        docs/user-guide.md（插件管理 / 套餐管理章节）
```
