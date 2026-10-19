# 部署文档：本地 Telegram Bot API Server

> 相关文档：[部署](deployment.md) · [Telegram 模块开发](telegram-module.md) · [数据库说明](database.md)
> 最后更新：2026-10-19
> 目标：把 Bot API 调用从 `api.telegram.org` 换成本机 TDLib 服务，降低延迟、突破文件大小限制

---

## 1. 它解决什么问题 / 不解决什么

**解决**

| 收益 | 说明 |
|---|---|
| 延迟 | 调用走内网/回环，省掉到 `api.telegram.org` 的公网往返（服务器网络差时提升明显） |
| 文件大小 | 云端限制**下载 20MB / 上传 50MB**；本地服务加 `--local` 后最大 **2GB** |
| 稳定性 | 不再依赖公网直连 Telegram（被墙/抖动时群发、收图照样能跑） |
| 客户端无感 | bot token、方法名、返回结构都不变，只是换个 base url |

**不解决（重要）**

- **真人客服（Telethon / MTProto）不受影响。** 真人账号走的是 MTProto 协议，由
  `telegram-service` 容器（8081）承担，和 Bot API 是两套完全不同的协议。
  想给真人侧提速，方向是优化 `telegram-service` 到 Telegram DC 的网络、复用 session、
  减少不必要的 media 下载，而不是部署本服务。
- 不改变 Telegram 的**限流规则**。429 + `retry_after` 依然要遵守
  （`TelegramMessageService::callTelegramApi()` 已按 `retry_after` 退避重试）。

---

## 2. 前提条件

| 项 | 说明 |
|---|---|
| `api_id` / `api_hash` | 到 <https://my.telegram.org> → *API development tools* 申请。**等同账号凭证，进 `.env` / secrets，不要提交 git** |
| 网络 | 服务器能访问 Telegram（就近 DC，或配代理，见 6.3） |
| 磁盘 | `--dir` 是文件缓存，持续增长，建议独立卷 ≥ 20G |
| 内存 | 单实例约 200–500MB（每个 bot 的会话常驻 TDLib） |
| 端口 | 官方默认的 8081 在本项目已被 `telegram-service`（Telethon）占用，compose 里固定用 **8083** |

---

## 3. 部署方式

### 3.1 Docker（推荐，已写进项目 docker-compose.yml）

镜像用社区维护的 `aiogram/telegram-bot-api`（跟随官方 release 打 tag）。

**服务定义已经在仓库根目录的 `docker-compose.yml` 里**（服务名 `telegram-bot-api`，
容器名 `tgbot-bot-api`，共享 `stack` 网络、**不映射宿主端口**，缓存卷 `tgbot_botapi`）。
本节只保留"为什么这么配"，**不再复制一份 yaml** —— 两处配置必然互相漂移。

> ⚠️ **这个镜像只能用环境变量配置，不要写 `command:`。**
> 镜像的 `docker-entrypoint.sh` 用环境变量拼命令行，最后一行是 `exec $COMMAND`
> （不带 `"$@"`），compose 里写的 `command` 会被**静默丢弃**；
> 而它校验的 `TELEGRAM_API_ID` 又必须存在于容器 env 里。两个条件缺一个就会起来就退出并刷：
> `error: expected TELEGRAM_API_ID or TELEGRAM_API_ID_FILE env vars to be set`

启用（三步，少一步等于没启用）：

```bash
# 1. 项目 .env：TDLib 身份（my.telegram.org 申请） + 出站地址
TELEGRAM_API_ID=123456
TELEGRAM_API_HASH=你的api_hash
TELEGRAM_BASE_BOT_URL=http://telegram-bot-api:8083/bot

# 2. 只起这一个服务，不影响其它容器
docker compose up -d telegram-bot-api
docker logs -f tgbot-bot-api

# 3. Laravel 重新读配置（用了 config:cache 的必须再 cache 一次）
docker exec tgbot-php php artisan config:clear
```

> 后续更新镜像也是同一条：`docker compose up -d telegram-bot-api`
> （先 `docker compose pull telegram-bot-api` 拉新 tag 也行）。

**参数要点**（对应 compose 里 `environment:` 那一段，改参数请改 compose。
下表左边是环境变量，右边是 entrypoint 用它拼出的命令行参数）

| 环境变量 | 拼出的参数 | 作用 | 注意 |
|---|---|---|---|
| `TELEGRAM_API_ID` / `TELEGRAM_API_HASH` | （不拼参数，由 TDLib 直接读 env） | TDLib 身份 | **必填**，空了容器起来就退出。也可改用 `*_FILE` 指向 secret 文件，二选一 |
| `TELEGRAM_WORK_DIR` | `--dir` | 文件缓存目录 | **必填**，entrypoint 启动时对它 chown；必须持久化，见第 5 节 |
| `TELEGRAM_TEMP_DIR` | `--temp-dir` | 上传临时目录 | **必填**。容器里用 `/tmp`（必定存在且可写，没有 systemd 的 `PrivateTmp` 隔离）；宿主机部署时别放会被隔离的路径 |
| `TELEGRAM_LOCAL` | `--local` | 开启本地模式 | **必须给**，否则文件大小限制和云端一样 |
| `TELEGRAM_HTTP_PORT` | `--http-port` | HTTP 端口 | 默认 8081，本项目改 8083 避开 Telethon |
| `TELEGRAM_LOG_FILE` | `--log` | 日志文件 | 落在持久卷里，方便 `docker exec` 看 |
| `TELEGRAM_VERBOSITY` | `--verbosity` | 日志级别 | 2 够排查，磁盘紧张降到 1 |

> 镜像还支持 `TELEGRAM_STAT`（`--http-stat-port`）、`TELEGRAM_FILTER`、
> `TELEGRAM_MAX_CONNECTIONS`、`TELEGRAM_MAX_WEBHOOK_CONNECTIONS`、
> `TELEGRAM_PROXY`、`TELEGRAM_HTTP_IP_ADDRESS`，需要时按同样规则加到 `environment:` 里。

### 3.2 从源码构建（要锁版本时）

```bash
sudo apt install -y cmake g++ git libssl-dev zlib1g-dev libreadline-dev

git clone --recursive https://github.com/tdlib/telegram-bot-api.git
cd telegram-bot-api
git checkout v1.0.0            # 以 GitHub Releases 上的 tag 为准

mkdir build && cd build
cmake -DCMAKE_BUILD_TYPE=Release ..
cmake --build . --target install -j$(nproc)
```

### 3.3 systemd（宿主机常驻）

`/etc/systemd/system/telegram-bot-api.service`：

```ini
[Unit]
Description=Telegram Bot API Server (local TDLib)
After=network-online.target

[Service]
Type=simple
User=telegram-bot-api
Group=telegram-bot-api
ExecStart=/usr/local/bin/telegram-bot-api \
  --api-id=123456 --api-hash=你的api_hash \
  --local \
  --http-port=8083 --http-ip-address=127.0.0.1 \
  --dir=/var/lib/telegram-bot-api --temp-dir=/var/lib/telegram-bot-api/tmp \
  --log=/var/log/telegram-bot-api/telegram-bot-api.log \
  --verbosity=2
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=false          # 必须 false：true 会给服务独立 /tmp，上传的临时文件 TDLib 自己也看不到
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
```

```bash
sudo mkdir -p /var/lib/telegram-bot-api /var/log/telegram-bot-api
sudo useradd -r -s /usr/sbin/nologin telegram-bot-api
sudo chown -R telegram-bot-api:telegram-bot-api /var/lib/telegram-bot-api /var/log/telegram-bot-api

sudo systemctl daemon-reload
sudo systemctl enable --now telegram-bot-api
systemctl status telegram-bot-api
```

> `--http-ip-address=127.0.0.1` 只监听回环，适合 Laravel 与它同机的部署；
> 跨机访问时改成内网 IP，并配合防火墙只放行来源。

---

## 4. 接入本项目

### 4.1 主路径：给 SDK 配 base url（一处配置，全部生效）

`config/telegram.php`（项目级配置，已随代码提交）：

```php
<?php

return [
    // ★ 指向本地 Bot API Server；不配则走官方 https://api.telegram.org/bot
    'base_bot_url' => env('TELEGRAM_BASE_BOT_URL', 'https://api.telegram.org/bot'),
];
```

> 这个配置文件里**只有 base url，没有 bot token**：本项目是多 bot 多商户，
> 每个机器人的 token 都在后台「机器人管理」（`bmbots.api_token`），
> 业务侧按订单 / 绑定 / 会话确定用哪个机器人，不设全局兜底 bot。

`.env`：

```bash
# Docker / stack 网络（服务名即上面的容器名）
TELEGRAM_BASE_BOT_URL=http://telegram-bot-api:8083/bot

# 宿主机同机部署
# TELEGRAM_BASE_BOT_URL=http://127.0.0.1:8083/bot
```

```bash
php artisan config:clear          # 用了 config:cache 的话重新 cache 一次
```

**⚠️ 为什么光有配置不够，还得改代码**：SDK 的 `Api` 只有在走 `BotsManager`
（`Telegram::bot()`）时才会读 `telegram.base_bot_url`；本项目的 `BotApiFactory`
是直接 `new Api($token)`，这条路**读不到配置**。所以工厂里显式把配置作为
第 4 个参数传给 `Api`：

```php
// modules/Telegram/Services/Bot/BotApiFactory.php
return new Api($token, false, null, $this->baseBotUrl());
```

SDK 的文件下载地址（`TelegramClient::getFileUrl()`）是从同一个 base 推导的，
所以**下载文件也自动走本地服务**，大图、表情、头像一起受益。

> 只放 `base_bot_url` / `bot_token` 两个键是有意的：其余键由 SDK 自带配置兜底
> （`mergeConfigFrom` 会用本文件的同名键覆盖它）。没有用
> `vendor:publish --tag=telegram-config` 把整份 SDK 配置发布进来——那只会带来与 SDK 版本的漂移。

### 4.2 已改掉的三处硬编码

| 文件 | 用途 | 改法 |
|---|---|---|
| `Services/Bot/BotApiFactory.php` | 所有 `forBot()` / `forToken()` / `default()` | 读 `config('telegram.base_bot_url')`，作为 `new Api()` 第 4 个参数 |
| `Services/TelegramMessageService.php`（`callTelegramApi`） | 群发消息（文本 / 图片） | 原硬编码 `https://api.telegram.org/bot{token}/{method}`，改为读同一配置 |
| `Services/TelegramMediaService.php` | 下载图片 / 头像（`putImage` / `getImageUrl`） | 删掉 `TELEGRAM_API_URL` 常量，改为读同一配置；并支持传入对应机器人的 token（多机器人下 `file_path` 只有所属机器人的 token 才下载得到） |

> `TelegramMediaService::getImageUrl()` 里的 `Telegram::getFile()` 走的是 SDK 全局
> BotManager（`telegram.bots.mybot.token`）。多机器人场景下要拿到正确的 `file_path`，
> 还得把它换成 `BotApiFactory::forBot($bot)->getFile()`。目前这两个方法在项目里
> 还没有调用方，真正要用时再改。

### 4.3 真人客服不适用

真人侧走 MTProto（`telegram-service` 容器，8081），与 Bot API Server 无关，**不需要也不应该**改它的地址。
真人侧提速的方向见第 1 节。

### 4.4 验收

```bash
# 1. 端口通（不需要 token，能返回 404 就说明服务活着）
curl -i http://telegram-bot-api:8083/ | head -1

# 2. 用任意 bot token 调一个方法
curl -s http://telegram-bot-api:8083/bot<你的token>/getMe | head -c 200

# 3. 大文件：云端会拒 >20MB 的下载 / >50MB 的上传，--local 下应成功
curl -s -F "chat_id=<群ID>" -F "document=@/tmp/big.mp4" \
     http://telegram-bot-api:8083/bot<你的token>/sendDocument | head -c 200
```

再到后台跑一次群发、触发一次图片下载，看 `feature_logs` 和 `storage/logs` 无报错、耗时下降。

---

## 5. 运维注意事项

1. **磁盘会涨**：`--dir` 是文件缓存，只增不减。建议独立卷 + 定期清理。
   清理后对应 `file_path` 失效（需要重新下载），**不影响新消息**。
2. **文件不跨实例**：`file_path` / `file_id` 与实例绑定。
   不要把一个 bot 轮流指向不同实例，也不要跑多副本做负载均衡——
   除非所有请求都 sticky 到同一实例。**单实例 + 持久卷**最稳。
3. **重启不丢 token**：token 在 Telegram 侧，重启/升级只需重连，`--dir` 里的文件只要卷没删就还在。
4. **端口无鉴权**：这个 HTTP 端口**没有任何认证**，谁能连上谁就能用你挂的所有 bot token 发消息。
   Docker 方式只暴露在 `stack` 网络、不映射宿主端口；宿主机方式务必 `--http-ip-address=127.0.0.1` + 防火墙。
5. **`api_id` / `api_hash` 是凭证**：放 `.env` / secrets，`.env` 已在 `.gitignore` 里，别提交。
6. **和 `telegram-service` 的 8081 错开**：本项目 Telethon 服务占用 8081，
   compose 里 Bot API Server 固定用 **8083**，别改回去。

---

## 6. 排错

| 现象 | 原因 / 处理 |
|---|---|
| 容器起来就退出，日志刷 `expected TELEGRAM_API_ID or TELEGRAM_API_ID_FILE env vars to be set` | `.env` 里 `TELEGRAM_API_ID` / `TELEGRAM_API_HASH` 没填，或填了但 compose 没把它传进容器 env（这个镜像**不认 `command:` 里的 `--api-id`**）。填完 `.env` 后 `docker compose up -d --force-recreate telegram-bot-api` |
| 容器起来就退出 / `curl` 不通 | `api_id` / `api_hash` 没配或写错，或 `TELEGRAM_WORK_DIR` / `TELEGRAM_TEMP_DIR` 没给。`docker logs tgbot-bot-api` |
| 大文件仍报 `Request Entity Too Large` | 没加 `--local`；或 nginx `client_max_body_size` 太小（部署文档里是 50m，按需调大） |
| `getFile` 拿到的 `file_path` 下载 404 | 指向了另一个实例，或容器重建过缓存没了；重新下载即可 |
| 后台群发突然全失败 | `base_bot_url` 改了但服务没起。先做 4.4 第 1 步验收 |
| 真人侧速度没变化 | 正常，Telethon 不走 Bot API（见 1 / 4.3） |
| 429 变多 | 本地服务不改变 Telegram 限流；群发仍要遵守 `retry_after`（代码已处理） |
| 上传偶发失败 | 检查 `--temp-dir` 是否被 `PrivateTmp=true` 隔离（systemd 那段已显式设 false） |

---

## 7. 一页速查

```bash
# —— Docker ——
docker compose up -d telegram-bot-api
docker logs -f tgbot-bot-api
docker exec tgbot-bot-api sh -c 'wget -qO- localhost:8083/bot$TOKEN/getMe'

# —— 接入 ——
echo 'TELEGRAM_BASE_BOT_URL=http://telegram-bot-api:8083/bot' >> .env
php artisan config:clear
# 代码侧已无需改动：BotApiFactory / 群发 / 媒体下载都读 config('telegram.base_bot_url')

# —— 宿主机 ——
sudo systemctl enable --now telegram-bot-api
journalctl -u telegram-bot-api -f
```
