# 上游适配器（Upstream Adapter）编写指南

## 1. 解决什么问题

两个方向的规则不在同一方手里：

| 方向 | 谁定规则 | 现有机制 |
| --- | --- | --- |
| 上游 → 平台（推送/回调） | 我方 | 统一入站标准 + `access_key` / `secret_key` 头签名 + 路由（见 [对外 API 标准文档](api.md)） |
| 平台 → 上游（我们主动请求） | **上游** | 后台配路径/方法/固定头 + **每家一个适配器类** |

出站方向的差异不只是「字段叫什么」，还包括：参数放 header 还是 body、form 还是 json、签名怎么算、HTTP 200 但 `code != 0` 算不算失败、业务数据包在 `data` 还是 `result.wallet`、状态值 `PAID` 要不要翻译成 `success`、金额是分还是元。

后台配置能描述静态项，描述不了判定和计算；回复模板只产出字符串，也不产出「成功 / 失败」。所以这部分差异用**代码插件**吸收：接一条上游就给它建一个文件夹、写一个适配器类，把**这条上游**的私有请求/响应映射回平台标准。功能代码和回复模板永远只读标准结构，不知道当前接的是谁。

> 一条上游一套插件（`Adapters/<上游名>/`），`thirdapi_config.adapter` 存的是「这条上游选了哪个插件」的 key。不要按「返回结构 / 协议类型」造通用插件，也不要把一个插件套给所有上游。同一家厂商、协议完全相同的多个商户号可以共用同一个插件（后台各自选同一个 key）；协议不同就各自建目录。`api_url` / `token` / 密钥 / `path` 这些部署差异始终留在后台。

## 2. 数据流与三个对象

```
功能代码（标准参数：merchant_id / trade_id / amount）
   │
   ▼
UpstreamRequest  ── prepareRequest() ──▶ 厂商请求（改名 / 换位置 / form / 签名）
   │                                      ↑ 插件改这个可变对象
   ▼  HTTP 由各出站点发送（URL 拼装、token 注入仍归它们）
UpstreamResponse ── parseResponse() ──▶ UpstreamResult（success + 标准 data）
   │                                      ↑ 插件产出，raw 保留厂商原文
   ▼  toLegacyArray()
功能代码 / 回复模板（{{data.balance}}）；失败时拿 _error
```

| 对象 | 位置 | 作用 |
| --- | --- | --- |
| `UpstreamRequest` | `Services/Upstream/UpstreamRequest.php` | 出站请求描述，`prepareRequest()` 的输入/输出，可变 |
| `UpstreamResponse` | `Services/Upstream/UpstreamResponse.php` | 上游原始响应，只如实记录，不做业务判断 |
| `UpstreamResult` | `Services/Upstream/UpstreamResult.php` | 平台标准结果，`parseResponse()` 的输出 |

契约是 `Contracts/UpstreamAdapter.php`，两个运行时钩子：

- `prepareRequest(UpstreamRequest $request, ThirdApiConfig $upstream): UpstreamRequest`
- `parseResponse(UpstreamResponse $response, UpstreamRequest $request, ThirdApiConfig $upstream): UpstreamResult`

外加三个静态元信息方法 `key()` / `label()` / `description()`，后台下拉靠它们。

## 3. 五分钟写一个插件

契约上的 `prepareRequest()` / `parseResponse()` 已由 `BaseUpstreamAdapter` 实现，**不用覆写它们**。继承基类后只填三块：声明表、公共钩子、各接口方法。

以接一条叫 `AcmePay` 的上游为例：

1. **建文件夹**：在 `Services/Upstream/Adapters/` 下新建 `AcmePay/`，照第 12 节案例的目录结构放文件（主类 + 可选的字段映射等辅助文件）。一家上游一个文件夹，别放在 `Adapters/` 根目录；也别复用别家插件的字段映射。
2. **改身份**：主类改名 `AcmePayAdapter`，命名空间 `...\Adapters\AcmePay`；改 `key()` / `label()` / `description()`。key 用**这条上游的标识**，全局唯一、上线后不要改（它存进 `thirdapi_config.adapter`，改了会找不到类并静默回退直通）；别按「返回结构 / 协议类型」起通用名。
3. **声明接口**：`requestHandlers()` / `responseHandlers()` 各写一张 `接口 code => 方法名` 的表，只列有差异的接口。没登记的接口由基类原样透传，不用写 `else`；没有差异的表留空即可。
4. **填三处内容**：
   - `commonRequest()`：所有接口都要带的头、时间戳、公共参数（没有就不写）
   - `businessFailure()`：所有接口共用的业务码判定，返回 `null` = 通过
   - 声明表里的方法：只写这个接口自己的参数改写 / 响应归一
5. **不需要注册、不需要清缓存、不需要改数据库**：`config/upstream_adapters.php` 配的目录递归扫描，后台下拉自动出现。
6. **后台只给这一条上游选它**：三方配置 → 编辑这条上游 → 上游适配器选中 → 保存。别的上游留空 = 直通，不批量设置、不写回填迁移。

最小骨架（一个接口）：

```php
<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream\Adapters\AcmePay;

use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Upstream\Adapters\BaseUpstreamAdapter;
use Modules\Telegram\Services\Upstream\UpstreamRequest;
use Modules\Telegram\Services\Upstream\UpstreamResponse;
use Modules\Telegram\Services\Upstream\UpstreamResult;

class AcmePayAdapter extends BaseUpstreamAdapter
{
    public static function key(): string { return 'acme.pay'; }
    public static function label(): string { return 'AcmePay · 专用适配器'; }
    public static function description(): string { return '处理 code!=0、余额字段映射、币种嵌套。'; }

    // ① 声明表：新增接口只加一行
    protected function requestHandlers(): array
    {
        return ['merchant.balance' => 'prepareBalance'];
    }

    protected function responseHandlers(): array
    {
        return ['merchant.balance' => 'normalizeBalance'];
    }

    // ② 所有接口公共：业务码判定（没有就删掉这个方法）
    protected function businessFailure(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): ?UpstreamResult {
        if ($this->businessOk($response, ['code'], [0, '0'])) {
            return null;
        }

        return UpstreamResult::fail($this->businessMessage($response), $response->payload());
    }

    // ③ 这个接口自己的请求差异
    protected function prepareBalance(UpstreamRequest $request, ThirdApiConfig $upstream): UpstreamRequest
    {
        return $request->renameParams(['merchant_id' => 'mchId']);
    }

    // ④ 这个接口自己的响应归一 → 平台标准结构
    protected function normalizeBalance(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult {
        return UpstreamResult::ok(
            ['data' => [
                'balance' => $response->get('data.avl'),
                'currency' => $response->get('data.ccy'),
            ]],
            $response->payload(),
            httpStatus: $response->httpStatus
        );
    }
}
```

> 声明表里的方法必须是 `protected` / `public`（基类要调用），`private` 会被判为不可调用并安全透传。
> 没登记的接口自动原样透传，所以不用写「其它接口怎么办」。

**接第二个接口**，只改两张表、加两个方法，不写任何 `if`：

```php
protected function requestHandlers(): array
{
    return [
        'merchant.balance'     => 'prepareBalance',
        'merchant.trade.query' => 'prepareTradeQuery',    // 新增一行
    ];
}

protected function responseHandlers(): array
{
    return [
        'merchant.balance'     => 'normalizeBalance',
        'merchant.trade.query' => 'normalizeTradeQuery',  // 新增一行
    ];
}

// 再加对应的两个 protected 方法即可
```

N 个接口 = 两张表各 N 行 + N 对方法，分派逻辑在基类只有一份（见第 6 节）。完整可运行案例见第 12 节。

想从零实现（不继承基类）看 `Demo/DemoMinimalAdapter.php`：直接 `implements UpstreamAdapter`，自己处理传输层判断。实际项目里继承 `BaseUpstreamAdapter` 更省事。

## 4. 平台标准结构

标准结构 = **功能代码和回复模板取什么**。目前明确约定的：

| endpointCode | 标准 `data` | 消费方 |
| --- | --- | --- |
| `merchant.balance` | `data.balance`、`data.currency` | `Custom/Merchant/GetMerchantBalance.php` 与后台 `reply_template` 的 `{{data.balance}}` |

功能代码**只读这两个标准字段**，不认任何厂商私有别名。`GetMerchantBalance::handle()` 的完整调用链：

```
/ye → handle() → callEndpoint()
    → UpstreamTransport::prepare()  [插件改写请求]
    → HTTP
    → UpstreamTransport::parse()    [插件归一响应 → 标准数组]
    → 读 data.balance / data.currency → 模板或多语文案
```

`adapter` 留空 = 直通，此时上游若返回私有字段，功能拿不到 `data.balance` 会明确失败（`查询余额失败：data.balance`），不再渲染成「余额：-」这种看起来像成功的空结果。需要给**这条上游自己**在后台选对应插件（见第 7 节的目录组织），不是给所有上游统一套一个。

其它接口的标准结构由插件与对应功能类 / 模板约定。新接口请在功能类 `endpoints()` 的 `response_schema` 里写明，别只写在插件里。

`UpstreamResult::toLegacyArray()` 的兼容约定（沿用旧 `callEndpoint()` 返回格式）：

- 成功 → 归一化后的 `data`；若插件确实改过结构，另附 `_raw`（模板里 `{{_raw}}` 可看厂商原文）
- 失败 → `['_error' => 原因]`，功能侧 `isset($data['_error'])` 判断照旧生效

`DemoFullGatewayAdapter` 演示了按接口产出 `{data:{status, order_no, amount}}` 这类自定义标准结构。

## 5. 请求侧 API（`UpstreamRequest`）

| 方法 | 用途 |
| --- | --- |
| `renameParams(['merchant_id' => 'mchId'])` | 批量改参数名，`pathParams` 会跟着改，避免重复进 query |
| `removeParam('merchant_id')` | 删掉一个业务参数（已被路径占位消耗、上游不需要的） |
| `setParam()` / `hasParam()` | 加固定值、时间戳、签名字段 |
| `moveParamToHeader('mchId', 'X-Mch-Id')` | 参数挪到请求头，不再进 body/query |
| `moveParamsToQuery(['token'])` | 批量把参数挪进 URL query（上游要求某参数必须挂在 URL 上） |
| `putHeader()` | 加厂商私有头 |
| `setMethod()` / `setBodyMode('form')` | 改请求方法 / 编码 |
| `sendParamsInBody()` | GET 时把参数放进请求体（post-data），URL 保持干净；配合 `setBodyMode('form')` 即 form-data |
| `queryParams()` | 剔掉已被 URL 路径占位消耗的参数 |
| `remember()` / `recalled()` | 请求侧存值，响应侧再取（示例 3 用它保留平台原始订单号） |
| `path` | 厂商要求不同路径时直接改它，调用方会据此重拼 URL；**别改 `url`** |

凭证从 `$upstream`（`token` / `public_key` / `secrept_key`）取，不要写死在插件里。

## 6. 基类的流程编排与 helper

| 方法 | 用途 |
| --- | --- |
| `firstOf($response, ['data.avl', 'data.balance'])` | 从多个候选路径取第一个非空值 |
| `unwrap($response)` | 解包 `data` / `result.data` / `result` |
| `businessOk($response, $codePaths, $okCodes)` | 业务成功码判定 |
| `businessCode()` / `businessMessage()` | 取业务码 / 错误文案 |
| `fenToYuan()` | 分 → 元 |
| `excerpt()` | 截断错误页，别把整个 HTML 塞进回复 |

**按接口分派不用自己写 `if`**。基类提供四个可覆写钩子，N 个接口只是 N 行声明 + N 对方法：

| 钩子 | 作用 |
| --- | --- |
| `requestHandlers()` / `responseHandlers()` | 两张声明表：`接口 code => 方法名`；新增接口只加一行 |
| `commonRequest()` | 所有接口共用的请求改写（固定头、时间戳、appKey） |
| `businessFailure()` | 所有接口共用的业务失败判定；返回 `null` = 通过 |
| 声明表里登记的方法 | 只写该接口自己的参数改写 / 响应归一；必须是 `protected` / `public`（基类要调用） |

响应侧执行顺序：

1. `parseResponse()`：请求没发出去 / 超时 → fail
2. HTTP 非 2xx → fail（带响应体摘要；上游挂了时错误页不是 JSON，以前会静默变「余额：-」）
3. 200 + 空响应体 → 成功 `['_raw' => '']`（兼容老上游的空成功响应）
4. 200 + 非 JSON → fail
5. `businessFailure()` → 返回非 null 就直接失败
6. 查 `responseHandlers()` 分派；**未登记的接口原样透传**（data === raw，不附 `_raw`）

请求侧同理：`commonRequest()` → 查 `requestHandlers()` → 未登记的接口按平台标准参数原样发出。声明表里方法名拼错会记日志并安全透传，不会静默失效。

完整写法见第 12 节案例。`Demo/DemoFullGatewayAdapter.php` 里手写了 `match ($request->endpointCode)`，那是为了直观展示分派过程；**新插件请用声明表**。

### 6.1 不写适配器：功能自己解构（`responseHandlers`）

适配器解决的是「一条上游上所有功能共用」的协议差异。如果只有一个功能要用某个
非标上游，或者解构逻辑就是该功能自己的业务规则，可以在**功能驱动里**直接声明
解构表，不必再建 Adapter + ResponseMap：

```php
// 功能驱动（BaseCustomFeature 子类）里覆写：
protected function responseHandlers(): array
{
    return [
        // 接口 code => 解构回调（闭包，或 '方法名' / [$this, '方法名']）
        'merchant.balance' => fn ($response, $request, $upstream) => [
            'balance' => $response->get('data.available_funds'),
            'currency' => 'VND',
        ],
        'merchant.trade.query' => 'mapTradeQuery',
    ];
}
```

规则：

- **优先级**：功能自带解构 > 上游适配器 > 直通。功能声明了的接口不再经过
  适配器；没声明的接口行为完全不变。
- 传输层判定（请求没发出 / 非 2xx / 非 JSON）由 `UpstreamTransport` 统一做，
  回调只负责「JSON body → 平台标准结构」，不必重复判断。
- 回调返回：`array`（成功，data 即返回值）、带 `_error` 键的 `array`（失败，
  与 `callEndpoint` 错误约定一致）、`UpstreamResult`（原样使用）。
- 回调抛异常 / 返回非法值 → 自动回退到适配器解构并记日志
  `[上游适配器] 功能自带解构异常，已回退适配器`，业务链路不会挂。
- 上游侧的请求改写（签名 / 字段改名 / form 编码）仍然走适配器——功能解构
  只接管响应侧，两侧互不冲突。

## 7. 四个 Demo 的学习顺序

都在 `Services/Upstream/Adapters/Demo/`，后台下拉带「示例」标记，**不要在生产环境选中**。

| 顺序 | 文件 | 学什么 |
| --- | --- | --- |
| 1 | `DemoMinimalAdapter.php` | 不继承基类，从零 `implements` 的最小写法，看清完整判断顺序 |
| 2 | `DemoFlatBalanceAdapter.php` | 只改响应：业务码 `code!=0`、`available_funds → balance`、币种对象拍平、产出标准 `data` |
| 3 | `DemoLegacyFormAdapter.php` | 只改请求：字段改名、元转分、参数进 header、form 编码、MD5 签名；以及状态枚举翻译、`remember/recalled` |
| 4 | `DemoFullGatewayAdapter.php` | 一家厂商多个接口，按 `endpointCode` 分派；公共签名抽私有方法、嵌套解包、列表取第 0 项、分转元 |

### 目录组织：一个上游一个文件夹

`Adapters/` 下**按上游分目录**：接一条上游就给它建一个文件夹，这条上游的所有协议细节都放在里面：

```
Services/Upstream/Adapters/
├── BaseUpstreamAdapter.php     抽象基类（全局唯一）
├── DefaultAdapter.php          直通基线（全局唯一）
├── Demo/                       教学示例，不接生产
└── <上游名>/
    ├── <上游名>Adapter.php     实现 UpstreamAdapter，key 用这条上游的标识
    ├── Signer.php              这条上游的签名算法（可选）
    ├── FieldMap.php            这条上游的字段映射（可选）
    └── StatusMap.php           这条上游的状态字典（可选）
```

规则：

- `key()`、目录名、类名都用**这条上游的标识**，不要按「返回结构」「协议类型」起通用名字。`available_funds`、`result.wallet.avl` 这类字段只是某一条上游自己的返回长相，不是一种协议，更不属于平台。
- 那条上游的 `available_funds → data.balance`、`currency.code → data.currency` 映射，**只写在它自己的插件里**（写法照 `Demo/DemoFlatBalanceAdapter.php`）。换一条上游就新建一个文件夹，不要往别的插件里加别名。
- 同一家厂商、协议完全相同的多个商户号可以共用同一个插件（后台各自选同一个 key）；协议不同就各自建目录。`api_url`、token、密钥、path 继续留在后台三方配置里，不写进代码。
- 后台「三方配置 → 编辑 → 上游适配器」给**每一条上游显式选自己的插件**。`adapter` 留空 = 直通（原样发、原样返回）；不批量回填、不替上游猜它该用哪个插件。
- 所以升级后如果某条上游返回私有字段，只需要给**它自己**选对应插件，其它上游完全不受影响。

> 数据契约是单向的：功能只读标准字段，每条上游的插件负责产出标准字段。中间不落库 —— 库里只存「这条上游选了哪个插件」的 key（配置），响应解析结果只在当次请求内存里流转。

完整实战案例见第 12 节：从建目录、写响应归一、后台只绑这一条上游，到 `Http::fake()` 验证，逐步可照抄。

## 8. 兜底与零破坏

- `adapter` 留空、类被删掉、插件抛异常 → 自动回退 `DefaultAdapter`（原样发、原样返回、只看 HTTP 状态），业务链路不会整体挂掉。
- `UpstreamTransport::prepare()` 会在插件执行前留一份请求快照；插件改到一半抛异常时从快照重来，不会发出半套厂商参数。
- 异常会写日志 `[上游适配器] 插件异常，已回退直通`。表现是「功能正常但字段没归一」，看到这种现象先查日志，别以为是插件没生效。
- 插件里不要写库、发通知、抛异常给业务层；失败一律 `UpstreamResult::fail()`。

## 9. 覆盖面

已接入插件的三个通用出站点：

| 出站点 | 场景 |
| --- | --- |
| `Feature/Drivers/BaseCustomFeature::callEndpoint()` | 自定义功能主力（查余额 / 绑商户 / 限额 / OCR 等） |
| `Feature/UpstreamCaller::call()` | 交互按钮提交；`success` 驱动会话 `finish(DONE)` / `release()` 重试 |
| `Feature/Drivers/HttpRequestDriver::execute()` | 斜杠命令 / HTTP 请求驱动 |

有意**未**接入：

- `Services/Wallet/UpstreamWalletGateway.php`：走另一套 `WalletGateway` 契约，由 `config/wallet.php` 做全局单类替换，接进来会双重归一。要迁移请单独评估。
- `Feature/Drivers/Custom/Demo/TradeConfirm.php`：演示用的硬编码 HTTP，不是通用链路。

## 10. 上线清单

1. `php artisan catch:migrate telegram` —— 只新增 `thirdapi_config.adapter` 列（`2026_11_09_000002`）。**没有批量回填迁移**，不给所有上游统一套一个插件。
2. 若同时改了功能声明，再跑 `php artisan telegram:sync-features`。
3. 后台「三方配置 → 编辑 → 上游适配器」，给**每一条上游**显式选它自己的插件；留空 = 直通。下拉数据源是 `GET telegram/features/options?source=upstream_adapters`（复用已有路由；**新开路由在开了 `route:cache` 的环境不刷新 cache 会 404**）。
4. 先用一条测试上游选中，实调一次命令 / 按钮，对照 `_raw` 检查归一结果；其它上游保持不动。
5. 正式环境走 `./deploy.sh update`（迁移 + 功能同步 + 前端构建）。

## 11. 常见坑

- **改了 `key()`**：库里存的旧 key 找不到类，静默回退直通，字段映射悄悄失效且功能不报错。
- **不判业务成功码**：HTTP 200 + `code != 0` 会被当成成功，按钮点完钱没动却提示成功。
- **金额单位**：厂商给分、模板按元显示，用 `fenToYuan()`。
- **造「通用 XX 协议」插件 / 批量回填**：某一家上游的返回字段不是协议，把它做成通用插件再套给所有上游，等于假设所有上游返回结构相同。一家一个文件夹，后台逐条选。
- **往功能类里加 `??` 兼容链**：`GetMerchantBalance` 已改成只读标准字段；字段别名写在对应上游自己的插件里，别跨插件攒兼容链。
- **把厂商差异写进后台配置**：能配置的是路径 / 方法 / 固定头这类静态项；判定、计算、状态映射属于代码。
- **插件里读死密钥 / 写死 URL**：部署差异留后台，插件只处理协议。

## 12. 完整案例：ocean测试上游

> 教学用的假上游（`api_url = https://example.com`），下面的协议约定是为案例人为设定的，
> 不是真实抓包。接真实上游时把字段名、业务码换成厂商文档的值，目录结构和写法不用变。

### 12.1 最终形态

一条上游一个文件夹，这条上游的所有协议细节都在里面：

```
Services/Upstream/Adapters/Ocean/
├── OceanAdapter.php   唯一实现 UpstreamAdapter 的类，后台下拉里出现它
└── ResponseMap.php    这条上游的响应字段映射（不实现 UpstreamAdapter，扫描时自动跳过）
```

| 项 | 值 |
| --- | --- |
| 插件 key | `ocean.test`（存进 `thirdapi_config.adapter`，上线后不要改） |
| 用在哪条上游 | 只有 `ocean测试上游` 这一条配置 |
| 请求侧 | 全部直通：GET + 平台标准参数原样，鉴权只有 `Authorization: Bearer <token>`（`callEndpoint` 统一加） |
| 响应侧 | `{"code":0,"msg":"ok","data":{...}}`；`code != 0` 视为业务失败（HTTP 仍 200） |

### 12.2 主类（只写差异）

```php
class OceanAdapter extends BaseUpstreamAdapter
{
    public static function key(): string { return 'ocean.test'; }
    public static function label(): string { return 'ocean测试上游 · 专用适配器'; }
    public static function description(): string { return '……'; }

    // 这条上游的 GET 都只要 token、不需要签名：请求侧没有差异，
    // requestHandlers() 留空即可（基类把标准参数原样发出）。

    protected function responseHandlers(): array
    {
        return [
            'merchant.balance' => 'normalizeBalance',
            'merchant.check' => 'normalizeCheck',
            'merchant.trade.query' => 'normalizeQuery',
        ];
    }

    // 所有接口公共：业务码判定。HTTP 200 + code != 0 也是失败，必须判，
    // 否则按钮会误判成功
    protected function businessFailure(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): ?UpstreamResult {
        if ($this->businessOk($response, ['code'], [0, '0', 'SUCCESS'])) {
            return null;
        }

        return UpstreamResult::fail(
            $this->businessMessage($response),
            $response->payload(),
            $this->businessCode($response),
            $response->httpStatus
        );
    }

    protected function normalizeBalance(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult {
        // data 是平台标准结构；raw 保留厂商原文
        return UpstreamResult::ok(
            ResponseMap::balance($response),
            $response->payload(),
            $this->businessCode($response),
            $response->httpStatus
        );
    }

    // normalizeCheck / normalizeQuery 同构，见 Adapters/Ocean/OceanAdapter.php
}
```

要点：

- **没有差异就不写代码**：这条上游三个接口都只要 token，所以声明表只有响应侧一排。
- 插件只做字段归一，不替业务下结论：`merchant.check` 把上游返回的商户号放进标准 `data.id`；「它是否等于 `/bd` 输入的商户号」由 `BindMerchant` 判定，相等才走平台绑定。别在插件里造 `exists` 或业务码字典。
- 字段映射放 `ResponseMap.php`，**不要**往里加 `?? $wallet['balance'] ?? $wallet['avl']` 这类兼容候选 —— 那是别的上游的字段，属于别的文件夹。
- 以后这条上游要接「参数改名 / 签名 / 参数放 post-data / form 编码」的接口：在 `requestHandlers()` 加一行 + 写一个 handler（`renameParams()` / `sendParamsInBody()` / `setBodyMode('form')` / 签名），API 见第 5 节，完整示例看 `Demo/DemoLegacyFormAdapter.php` 与 `Demo/DemoFullGatewayAdapter.php`。

### 12.3 后台绑定与验证

1. 三方配置 → 编辑 `ocean测试上游` → 上游适配器选 `ocean.test` → 保存。**只改这一条**，其它上游留空 = 直通，不批量设置。库里变动的只有 `thirdapi_config.adapter` 这一个字符串，解析结果不落库。
2. `api_url` 是假地址，用 `Http::fake()` 跑完整链路：

```php
Http::fake(['*' => Http::response([
    'code' => 0,
    'data' => [
        'available_funds' => '978479062.00',
        'currency' => ['code' => 'VND', 'symbol' => '₫'],
    ],
], 200)]);

// 后台给这条上游选 ocean.test（等价于 $third->adapter = 'ocean.test'），然后跑 /ye：
$result = app(GetMerchantBalance::class)->handle($ctx);
// $result->message === '余额：978479062.00 VND'

Http::assertSent(fn ($req) =>
    str_contains($req->url(), 'merchant_id=900001')
    && $req->hasHeader('Authorization', 'Bearer ' . $third->token)
);
```

3. 接真实上游后的排查顺序：

| 现象 | 先看哪里 |
| --- | --- |
| 下拉里没有 `ocean.test` | 类是否放在扫描目录下、是否实现了 `UpstreamAdapter`、`key()` 是否为空 |
| 回复「查询余额失败：data.balance」 | 这条上游没选插件（留空=直通），或 `ResponseMap` 没产出 `data.balance` |
| 功能正常但字段没归一 | 查 `laravel.log` 的 `[上游适配器] 插件异常，已回退直通` |
| 想看厂商原文 | 后台 `reply_template` 填 `{{_raw}}` |

### 12.4 换成你自己的上游，改这几处

1. 复制整个 `Ocean/` 文件夹，改名成 `<你的上游名>/`，类名、`key()`、`label()` 一起改。
2. 请求侧有差异的接口（改名 / 签名 / 参数放 body / form 编码），在 `requestHandlers()` 加一行 + 写一个方法；所有接口共用的头、时间戳、公共参数放 `commonRequest()`。
3. `businessFailure()` 换业务码路径与成功码；各接口响应归一在 `responseHandlers()` 加一行 + 写一个方法，字段映射放你自己的 `ResponseMap.php`。
4. 后台给**那一条**上游选新 key，其它上游不动；用 `Http::fake()` 按上面跑一遍，再换真实 `api_url` 联调。
5. `GetMerchantBalance` 和回复模板**一行都不用改** —— 它们只读 `data.balance` / `data.currency`。
