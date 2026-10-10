# Demo：交易确认（入站验签 / 出站加签）

一个可运行的业务样板：**上游推交易过来 → 平台验签 → 群里发确认按钮 → 操作员点按钮 → 平台加签提交上游 → 回复群里**。

密钥是双方约定的一把，平台侧不存明文以外的东西。

---

## 1. 文件在哪

| 文件 | 作用 |
|---|---|
| `modules/Telegram/Services/Feature/Drivers/Custom/Demo/TradeSigner.php` | **签名器**。换算法只改这一个文件 |
| `modules/Telegram/…/Custom/Demo/TradeNotify.php` | 收回调：验签 → 防重放 → 定位群 → 建会话 → 发按钮 |
| `modules/Telegram/…/Custom/Demo/TradeConfirm.php` | 点按钮：校验 → 抢锁 → 加签提交上游 → 回复 → 撤按钮 |

照着做自己的业务：**复制 `Demo/` 目录改名**，改三处——签名规则（`TradeSigner`）、取字段（两个 `handle()`）、配置项（`configSchema()`）。

> 如果你的上游就是标准规则（除 `sign` 外非空字段 `ksort` 后 HMAC-SHA256），
> **不用写这两个类**：后台把密钥填进 hook 的「验签密钥」，执行器选通用的
> `交易通知 → 群内按钮` + `交互按钮点击处理` 即可。

---

## 2. 时序

```
上游                         平台                             群
 │ POST /api/hooks/{token}    │                                │
 │  (含 sign)            ───▶ │ ① 验签 ② timestamp/nonce 防重放  │
 │                            │ ③ 商户号 → 路由表 → 群 + 机器人   │
 │                            │ ④ 建会话（按钮里只有随机 code）   │
 │                            │ ────────── 带按钮的消息 ───────▶ │
 │                            │                                │ 操作员点「确认」
 │                            │ ◀──── callback_query ───────── │
 │                            │ ⑤ 归属/过期/权限/动作码 校验      │
 │                            │ ⑥ claim() 原子抢锁              │
 │      POST /trade/submit    │                                │
 │  ◀──────────────────────── │ ⑦ 加签后提交                    │
 │      200 OK                │                                │
 │                            │ ────────── 「✅ 交易已确认」───▶ │
 │                            │            并撤掉按钮            │
```

---

## 3. 签名协议（给上游看这段）

### 待签串

1. 去掉 `sign` 字段本身
2. 去掉值为 `null` / `''` / `[]` 的字段
3. 剩下按 **key 升序** 排（`ksort`）
4. `k=v` 用 `&` 连接
5. 数组/对象用 `json_encode`（不带转义斜杠与 unicode）

例：

```
amount=199.00&merchant_id=M8888&nonce=R6W7eiaeTKxYP1pb&timestamp=1791647180&trade_no=D10001
```

### 签名值

```
sign = strtoupper( HMAC_SHA256(待签串, 密钥) )      // 推荐
sign = strtoupper( MD5(待签串 + "&key=" + 密钥) )   // 老上游
```

### 上游 → 平台（交易通知）

`POST https://你的域名/api/hooks/{token}`，`application/x-www-form-urlencoded`

| 字段 | 必填 | 说明 |
|---|---|---|
| `merchant_id` | 是 | 商户号，平台靠它决定发到哪个群 |
| `trade_no` | 是 | 上游单号，同时用作幂等键 |
| `amount` | 否 | 金额，只用于展示 |
| `currency` | 否 | 币种 |
| `status` | 否 | 上游侧状态 |
| `timestamp` | 是 | 秒级 Unix 时间戳，偏差超过 300s 拒收 |
| `nonce` | 是 | 随机串，窗口内不可重复 |
| `sign` | 是 | 上面算出来的签名 |

应答：`{"status":"success"}` / `{"status":"error","message":"..."}`（HTTP 200 / 500）

### 平台 → 上游（提交结果）

`POST {上游base_url}/api/merchant/trade/submit`

| 字段 | 说明 |
|---|---|
| `merchant_id` | 商户号 |
| `trade_no` | 单号 |
| `action` | 动作码，对应按钮（`confirm` / `reject` …） |
| `operator_id` / `operator_name` | 点击人的 Telegram ID / 用户名 |
| `timestamp` / `nonce` | 同上，供上游防重放 |
| `sign` | 同一把密钥算出的签名 |

> 上游请务必：① 先验签再执行；② 按 `nonce` + `timestamp` 拒绝重放；③ 按 `trade_no` 做幂等（同单号重复提交返回成功但不重复执行）。

---

## 4. 上游侧参考实现

### PHP

```php
function buildString(array $params): string
{
    unset($params['sign']);
    $params = array_filter($params, fn ($v) => $v !== null && $v !== '' && $v !== []);
    ksort($params);
    return urldecode(http_build_query($params)); // 注意别做 urlencode，两边要一致
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

### Go

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

### 自测 curl

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

## 5. 后台怎么配

1. **三方配置**：建一个上游，填 `base_url`、密钥（`secrept_key`）
2. **群里**发 `/trbd M8888` 绑商户号，再发 `/trsq @zhangsan` 指定谁能点
3. **功能列表 → 新建**：执行器 `[示例]交易通知`，触发方式 `webhook`
   → 生成 hook token，把 `https://域名/api/hooks/{token}` 给上游
   → 配置里填「签名密钥」或选刚才建的上游
4. **再建一个功能**：执行器 `[示例]交易确认`，触发方式 `callback_query`
   → 配置提交路径、成功/失败/驳回话术
5. 两个功能都**绑定到那个群**，绑定上可指定该群走哪个上游

---

## 6. 已经替你处理掉的坑

| 坑 | 处理 |
|---|---|
| 改金额/改单号后重发 | 签名覆盖全部字段，改一个就验不过 |
| 抓一条合法通知反复重放 | `timestamp` 窗口 + `nonce` 去重 + `trade_no` 幂等 |
| 按钮里带商户号/金额 | 按钮只带随机 `code`（17 字节），数据全在服务端会话里 |
| 按钮被转发到别的群点 | 校验 `session.chat_id` 与当前群一致 |
| 两个人同时点 / Telegram 重投 | `claim()` 用 `where status='pending'` 的原子更新抢锁，只提交一次 |
| 谁都能点 | 默认群管理员，`/trsq` 可指定白名单 |
| 点完一直转圈 | 每次点击都 `answerCallbackQuery` |
| 同一个按钮被反复点 | 处理完立刻 `editMessageReplyMarkup` 撤掉按钮 |
| 上游失败就卡死 | 失败回滚成 `pending`，可以再点一次（次数有记录） |
| 回调失败后上游重试被吞 | hook 的去重键只在**执行成功**后才占住（见 `HookController`） |

---

## 7. 验证过的自测项（16 项）

合法签名受理 · 篡改字段被拒 · 错密钥被拒 · 过期时间戳被拒 · nonce 重放不建新会话 ·
非操作人点击被拒且不刷群 · 跨群点击被拒 · 操作人确认后群里收到成功提示并撤按钮 ·
**出站报文已加签且用同一把密钥能验过** · 重复点击不重复提交 · 驳回不调上游
