# 部署文档：本地 Telegram Bot API Server

> 相关文档：[部署](deployment.md) · [Telegram 模块开发](telegram-module.md) · [数据库说明](database.md)
> 最后更新：2026-10-16
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
| 端口 | HTTP 默认 `8081`（本项目 `telegram-service` 用 8081，**注意避开**，可用 8083） |

---

## 3. 部署方式

### 3.1 Docker（推荐）

镜像用社区维护的 `aiogram/telegram-bot-api`（跟随官方 release 打 tag）。

接入本项目 `docker-compose.yml`（共享 `stack` 网络，**不映射宿主端口**）：

```yaml
  # ---------------------------------------------------------- Telegram Bot API Server（本地 TDLib）
  telegram-bot-api:
      image: aiogram/telegram-bot-api:latest
      container_name: tgbot-bot-api
      # 参数写全，避免依赖镜像自己的 env 约定
      command: >
        --api-id=123456 --api-hash=你的api_hash
        --local
        --http-port=8083
        --dir=/data
        --temp-dir=/tmp/telegram-bot-api
        --log=/data/telegram-bot-api.log
        --verbosity=2
      volumes:
          - tgbot_botapi:/data
      restart: unless-stopped
      networks:
          - stack
```

`volumes` 里补一行：

```yaml
volumes:
    node_modules:
    tgbot_media:
    tgbot_redis:
    tgbot_botapi:      # ← 新增
```

**参数要点**

| 参数 | 作用 | 注意 |
|---|---|---|
| `--api-id` / `--api-hash` | TDLib 身份 | 必填，写错容器起不来 |
| `--local` | 开启本地模式 | **必须加**，否则文件大小限制和云端一样 |
| `--http-port` | HTTP 端口 | 避开已占用的 8081（本项目 Telethon 在用） |
| `--dir` | 文件缓存目录 | **必须持久化**，见第 5 节 |
| `--temp-dir` | 上传临时目录 | 显式指定，别放 `PrivateTmp` 命名空间里 |
| `--verbosity` | 日志级别 | 2 够排查，磁盘紧张降到 1 |

```bash
docker compose up -d telegram-bot-api
docker logs -f tgbot-bot-api
```

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

项目里所有 `BotApiFactory::forBot()` → `new Api($token)` → SDK 的 `TelegramClient`，
它的 base url 来自 Laravel 配置 **`telegram.base_bot_url`**。项目目前没有发布这个配置文件，
补一个 `config/telegram.php`：

```php
<?php

return [
    // 后台每个机器人自带 token，这里只是 SDK 的占位默认值
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),

    // ★ 指向本地 Bot API Server。注意要写到 /bot 这一级，
    //   SDK 会在后面自己拼 <token>/<method> 和 /file/bot<token>/<path>
    'base_bot_url' => env('TELEGRAM_BASE_BOT_URL', 'https://api.telegram.org/bot'),

    'async' => false,
    'http_client_handler' => null,
];
```

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

SDK 的文件下载地址（`TelegramClient::getFileUrl()`）是从同一个 base 推导的，
所以**下载文件也自动走本地服务**，大图、表情、头像一起受益。

### 4.2 两处硬编码也要改，否则只用上一半

| 文件 | 用途 | 现状 |
|---|---|---|
| `modules/Telegram/Services/TelegramMessageService.php:218` | 群发消息（文本 / 图片） | 硬编码 `https://api.telegram.org/bot{token}/{method}` |
| `modules/Telegram/Services/TelegramMediaService.php:15` | 下载图片 / 头像（`putImage`） | 常量 `TELEGRAM_API_URL = 'https://api.telegram.org'` |

这两处是**群发**和**媒体下载**的主路径，不改的话本地服务对这两个最耗时的场景没有收益。
改成读同一个配置即可：

```php
// TelegramMessageService::callTelegramApi()
$url = rtrim((string) config('telegram.base_bot_url', 'https://api.telegram.org/bot'), '/')
     . "/{$botToken}/{$method}";

// TelegramMediaService：删掉 TELEGRAM_API_URL 常量，取文件时用
$base = rtrim((string) config('telegram.base_bot_url', 'https://api.telegram.org/bot'), '/bot');
// 下载地址：$base . "/file/bot{$token}/{$filePath}"
```

> 这两处 + `config/telegram.php` 要我一起提交的话说一声。

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
6. **和 `telegram-service` 的 8081 错开**：本项目 Telethon 服务占用 8081，本文示例用 8083。

---

## 6. 排错

| 现象 | 原因 / 处理 |
|---|---|
| 容器起来就退出 / `curl` 不通 | `api_id` / `api_hash` 没配或写错。`docker logs tgbot-bot-api` |
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
# 再按 4.2 改掉两处硬编码（群发 / 媒体下载）

# —— 宿主机 ——
sudo systemctl enable --now telegram-bot-api
journalctl -u telegram-bot-api -f
```
