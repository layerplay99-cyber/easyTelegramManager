# 对外 API 标准文档（三方对接）

> 面向第三方（上游）系统对接本平台的 HTTP API。最后更新：2026-11-10。
> 平台侧实现：`app/Http/Middleware/ValidateUpstreamSignature.php`、`modules/Telegram/Services/Security/UpstreamSignature.php`。

## 1. 接入流程

1. 平台在后台「三方 API 配置」为你的接入生成一对密钥：
   - `access_key`（`ak_...`）：公开标识，随请求头携带；
   - `secret_key`（`sk_...`）：签名密钥，**明文只在生成时显示一次**，请妥善保存。
2. 先调 `POST /api/upstream/v1/ping` 验证连通性与签名实现。
3. 再接业务回调地址 `POST /api/hooks/{token}`（由平台「回调设置」下发）。

密钥吊销 / 轮换：`access_key` 状态非激活一律 401。密钥用平台 `APP_KEY` 加密存储，平台侧更换 `APP_KEY` 需要所有三方重新生成密钥（见 [部署文档 §12.4](deployment.md)）。

## 2. 鉴权：头签名（必须）

所有 `validate.upstream` 下的请求（`/api/upstream/v1/*`、`/api/hooks/{token}`）必须带：

| 请求头 | 值 |
|---|---|
| `X-Access-Key` | 平台下发的 `access_key` |
| `X-Timestamp` | Unix 秒；与服务器时差 ≤ 300 秒 |
| `X-Nonce` | 随机串，`[A-Za-z0-9_-]+`，≤ 64 位；600 秒窗口内不得重复 |
| `X-Signature` | `HMAC-SHA256(canonical, secret_key)` 的小写 hex（64 位） |

**待签串 canonical（`\n` 分隔，末尾无换行）**：

```
METHOD
PATH?SORTED_QUERY
TIMESTAMP
NONCE
BODY_SHA256
```

- `METHOD`：大写 HTTP 方法
- `PATH`：URL 路径（不含 host）。带 query 时按 key 升序、键值 `rawurlencode` 后拼成 `?k=v&...`；无 query 就是纯路径（不带 `?`）
- `BODY_SHA256`：原始 body 字节的 SHA-256 小写 hex。**body 必须原样发送**（不要重新序列化 / 调整字段顺序），否则两边哈希对不上；无 body 按 `sha256('')` 计算

**校验顺序**（服务端固定）：头完整性 / 时间窗 / nonce 格式 → access_key 定位接入方 → 解密 secret → 比对签名 → 原子占用 nonce。任一步失败统一返回 `401`：

```json
{"message": "Unauthorized (invalid signature)"}
```

`message` 只会是 `invalid headers` / `unknown or revoked access key` / `key unavailable` / `invalid signature` / `replayed request` 之一，不回显任何密钥或签名细节。

**签名示例（PHP）**：

```php
$timestamp = (string) time();
$nonce     = bin2hex(random_bytes(8));
$body      = '';
$canonical = implode("\n", [
    'POST',
    '/api/upstream/v1/ping',
    $timestamp,
    $nonce,
    hash('sha256', $body),
]);
$signature = hash_hmac('sha256', $canonical, $secretKey);
// 用这组头发起请求，body 一字节不改地发出去
```

## 3. 接入自检：POST /api/upstream/v1/ping

无业务参数（body 可为空）。验签通过返回：

```json
{"status":"ok","access_key":"ak_...","tenant_id":0,"server_time":1762761600}
```

- `tenant_id`：这把密钥归属的商户（租户）id，`0` 表示平台共享
- `server_time`：服务器 Unix 秒，可用它校准本地时钟（时差超过 300 秒签名会失败）
- 限流：`throttle:120,1`（每分钟 120 次）

## 4. 业务回调：POST /api/hooks/{token}

`{token}` 由平台「回调设置」生成，**只在首次创建时生成、之后不变**；一个 token 对应一个回调功能。

**请求**：JSON body，字段由该功能的转换器约定。常用约定：路由字段（如 `data.merchant_id`）用于把回调路由到「商户 → 机器人 + 群」。

**应答**：

```json
{"status":"success","message":"..."}   // 接受，无需重试
{"status":"error","message":"..."}     // 拒绝，可按指数退避重试
```

| HTTP | status | 含义 |
|---|---|---|
| 200 | `success` | 已投递；重复推送被幂等吞掉时 `message` 为 `duplicate ignored` |
| 401 | - | 验签失败（见 §2） |
| 404 | `error` | 回调令牌无效，或功能不存在 / 已停用 |
| 422 | `error` | 未配置回调目标（机器人 / 群），或目标群与机器人不匹配 |
| 429 | - | 触发限流（`throttle:120,1`） |
| 500 | `error` | 处理失败，可重试 |

**幂等 / 重试约定**：

- 平台按该功能配置的 `dedup_field` 做幂等：相同字段值的重复推送直接返回成功。去重键只在**执行成功后**占住（24 小时），所以失败后按指数退避重试是安全的。
- 时间戳窗口、nonce 防重放由签名中间件完成，**不要**在 body 里再带 `timestamp` / `nonce`。

## 5. 其它对外入口（非三方对接面）

以下入口面向内部系统（采集器 App / 前端 / telegram-py / Telegram 官方 / miniapp），鉴权各自独立，不走本文档的头签名：

| 入口 | 鉴权 |
|---|---|
| `POST /api/bot/send/group` | `validate.apikey`（`EXTERNAL_API_KEY`） |
| `/api/collect/*`、`/api/tg/user/*`、`/api/webhook/set|del|info/{id}` | `validate.apikey` |
| `POST /api/webhook/pull` | Telegram 官方 secret_token |
| `POST /api/telegram/events/*` | 控制器内 X-Token（telegram-py 专用） |
| `/api/telegram/wallet/*` | 钱包网关验签 / Telegram initData |
