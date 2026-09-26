# 部署文档（总）：Telegram 机器人后台管理系统

> 适用范围：整套系统的生产环境部署（后端 API + 前端 SPA + 常驻进程 + 实时广播）。
> 代码仓库：`layerplay99-cyber/easyTelegramManager`（分支 `main`）。
> 最后更新：2026-09-26

---

## 1. 系统架构概览

| 层 | 技术 | 说明 |
|---|---|---|
| 后端 API | Laravel 12（CatchAdmin 模块化，`Modules/` 命名空间） | PHP 8.2，提供 `/api/*` 接口 |
| 前端 SPA | Vue 3 + TypeScript + Vite + Element Plus | 位于 `web/`，构建产物 `web/dist` |
| 数据库 | **MySQL 8.0+**（生产要求；开发 docker-compose 用的是 5.7 镜像，仅本地参考） | 见 `config/database.php`，collation `utf8mb4_unicode_ci` |
| 缓存 / 队列 / 会话 | Redis | `ext-redis` 必装，生产建议 `QUEUE_CONNECTION=redis` |
| 实时广播 | Laravel Reverb（WebSocket，默认 `:8080`） | 前端用 `laravel-echo` + `pusher-js`（兼容协议） |
| 对象存储 | AWS S3 / 阿里云 OSS（可选） | 见 `.env` 的 `AWS_*` / `ALIOSS_*` |
| 二次验证 | Google2FA | 后台登录 2FA |
| Telegram 客户端 | MadelineProto（`danog/madelineproto`） | 多账号会话，需常驻进程 |

**对外端口**：80/443（Web）。Reverb(8080)、php-fpm(9000)、MySQL(3306)、Redis(6379) 均只应内网暴露，Reverb 建议经 Nginx 反代为 `wss://`。

---

## 2. 服务器环境要求

- **操作系统**：Linux（Ubuntu 22.04 LTS 推荐）
- **Web 服务器**：Nginx 1.20+
- **PHP**：≥ 8.2（开发镜像 `jaguarjack/php82`，建议生产保持一致）
  - 必需扩展：`pdo`、`pdo_mysql`、`mysqli`、`redis`、`zip`、`fileinfo`、`mbstring`、`bcmath`、`ctype`、`curl`、`dom`、`xml`、`json`、`openssl`、`tokenizer`、`xmlwriter`、`session`、`gd`（或 `imagick`，供 intervention/image、endroid/qr-code 使用）
  - **系统二进制**：`tesseract-ocr`（供 `thiagoalessio/tesseract_ocr` OCR 功能，`apt install tesseract-ocr`）
- **Composer**：2.x
- **Node.js**：≥ 18（Vite 6），包管理用 Yarn 1 或 npm
- **MySQL**：**8.0+**（生产标准；`docker-compose.yml` 里的 `mysql:5.7` 仅作本地开发容器参考，不代表生产版本）
- **Redis**：≥ 6
- **Supervisor**：≥ 4（管理常驻进程）

```bash
# Ubuntu 扩展示例
sudo apt install -y php8.2-cli php8.2-fpm php8.2-mysql php8.2-redis php8.2-zip \
  php8.2-gd php8.2-mbstring php8.2-bcmath php8.2-xml php8.2-curl php8.2-intl \
  nginx redis-server supervisor tesseract-ocr
```

---

## 3. 获取代码

```bash
cd /var/www
git clone https://github.com/layerplay99-cyber/easyTelegramManager.git telegram-bot
cd telegram-bot
git checkout main
# 后续更新：git pull origin main
```

> 注意：当前本地仓库远程已切到 HTTPS，推送用新账号 PAT（`git push -u origin main`）。

---

## 4. 后端部署

### 4.1 安装依赖
```bash
composer install --no-dev --optimize-autoloader
```
> 若有 `catchadmin` 自有 fixer/脚本，按需执行；`post-autoload-dump` 会自动 `package:discover`。

### 4.2 配置环境变量
复制并编辑 `.env`：
```bash
cp .env.example .env
php artisan key:generate
```
关键配置项（生产应改为下述取值，默认 `.env.example` 是开发值）：

| 变量 | 生产建议值 | 说明 |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | 切勿 true |
| `APP_URL` | `https://你的域名` | |
| `DB_*` | 实际 MySQL 地址/库/账号 | |
| `BROADCAST_DRIVER` | `reverb` | 默认是 `log`，生产必须改 |
| `CACHE_DRIVER` | `redis` | |
| `QUEUE_CONNECTION` | `redis` | 默认 `sync` 会同步阻塞，生产改 redis |
| `SESSION_DRIVER` | `redis` | |
| `REDIS_*` | 实际 Redis | |
| `REVERB_APP_KEY` | 与前端 `VITE_REVERB_APP_KEY` **一致** | 当前开发值 `zsrxcva12bcezt6ghu6o` |
| `REVERB_HOST` | 你的域名 | 前端 `VITE_REVERB_HOST` 对应 |
| `REVERB_PORT` / `REVERB_SCHEME` | `443` / `https`（经反代）或 `8080`/`http`（直连内网） | |
| `AWS_*` / `ALIOSS_*` | 实际对象存储（可选） | 不用的留空 |
| `MAIL_*` | 实际 SMTP | 验证码/通知邮件 |
| `OPENAI_*` | 实际 Key（如用到 AI 功能） | |

### 4.3 安装与数据库初始化（CatchAdmin 命令）

> ⚠️ **顺序前置条件**：务必先创建好 `.env` 并配好 `DB_*`（见 4.2），否则 `catch:install` 会进入交互式建库流程，非交互部署会卡住。**生产严禁带 `--reinstall`**（该参数会 DROP 整个数据库并清空 `storage/app/modules.json`，仅用于本地重装）。

本项目 `modules/` 下共 7 个模块，安装方式分三类（**必须全部装好，后台才能用**）：

| 模块 | 有 Installer | `catch:install` 是否已装 | 部署命令 |
|---|---|---|---|
| `user` | 否 | ✅ 是（`catch:migrate user` + `catch:db:seed user`，且 `module.default` 默认启用） | 已由 `catch:install` 完成 |
| `develop` | 否 | ✅ 是（`catch:migrate develop`，`module.default` 默认启用） | 已由 `catch:install` 完成 |
| `common` | 否 | 默认启用（`module.default`），无独立迁移 | 无需额外命令 |
| `permissions` | ✅ | ✅ 是（`catch:install` 内部 `catch:module:install permissions`） | 已由 `catch:install` 完成 |
| `cms` | ✅ | ❌ 否 | `catch:module:install cms` |
| `system` | ✅ | ❌ 否 | `catch:module:install system` |
| `telegram` | ✅ | ❌ 否 | `catch:module:install telegram` |

> 即：`catch:install` **只装了 user/develop/permissions 三个核心模块**；`cms`、`system`、`telegram` 必须再单独安装，否则后台的内容管理、系统管理、Telegram 相关菜单 / 路由 / 权限都不会注册（这正是之前只写 Telegram 会漏掉用户·权限后台的坑）。

```bash
# 1) CatchAdmin 基础安装：key:generate + 发布配置 + migrate(user/develop) + seed(user) + 安装 permissions 模块
#    并产出初始后台账号 catch@admin.com / catchadmin
php artisan catch:install

# 2) 安装其余带 Installer 的模块（cms / system / telegram）
#    每个 install() 内部都会执行该模块的 catch:migrate + catch:db:seed（telegram 还额外 seedFeatures）
php artisan catch:module:install cms
php artisan catch:module:install system
php artisan catch:module:install telegram

# 3) 注册代码里实现的 Telegram 斜杠命令到 features 表（一次性，非常驻）
php artisan telegram:scan-activity
```

> **说明**
> - `catch:install` 完成会输出初始后台账号：**`catch@admin.com` / `catchadmin`**，上线后请立即改密码。
> - 模块安装信息写入 `storage/app/modules.json`（该文件**不进 git**，由安装命令生成）。部署后务必保证服务器上此文件存在且包含 cms/system/telegram 等全部模块，否则模块路由/权限不生效；若丢失，重跑对应 `catch:module:install {module}` 可重建。
> - `user` / `develop` / `common` 无 Installer，**不能**用 `catch:module:install`（会报找不到 Installer）；它们由 `catch:install` 与 `config/catch.php` 的 `module.default` 默认启用，迁移也已由 `catch:install` 完成。
> - 其它 CatchAdmin 模块命令：`catch:migrate {module}`（仅迁移，升级时用）、`catch:migrate:rollback {module}`、`catch:migrate:fresh {module}`、`catch:module:uninstall {module}`、`catch:export:menu {module}`。

> **本次合并迁移特别说明**
> - `modules/Telegram/database/migrations/2026_09_26_000003_merge_tusers_into_telegram_api_users.php`：把 `tusers` 合并进 `telegram_api_users`。**已确认新库 `tusers` 为空**，可直接迁移；合并后旧 `Tusers` 模型已删除。
> - `2026_09_26_000004_*`（菜单修复迁移）：自动把 `permissions` 表中原指向 `tUser` 页的菜单改指向 `telegramApiUser` 页，避免 404。
> - 合并后 `permissions` 表可能出现「两个菜单都指向 `telegramApiUser` 页」（原 `apiuser` 与改指向后的「账号仓库」），功能无害，仅导航重复。

### 4.4 存储软链与权限
```bash
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
# MadelineProto 会话目录需可写（见模块配置，默认 storage/app 下）
```

### 4.5 生产缓存优化（可选）
```bash
php artisan config:cache
php artisan route:cache        # 或 catch:route:cache（CatchAdmin 包装，等价）
php artisan view:cache
# 改 .env 或路由后需 php artisan config:clear / route:clear 再 cache
# 若新增/启用模块后路由 404，按顺序执行：route:clear → route:list → route:cache
```

---

## 5. 前端部署（`web/`）

前端为独立 SPA，Vite 构建。**`VITE_*` 变量在构建时注入**，改了必须重新 `build`。

```bash
cd web
# 生产构建用 .env.production（vite --mode production 会优先加载它；不存在则回退 .env）
# 也可直接编辑现有 web/.env，但务必填入生产域名，别把开发值打进包
cp .env.example .env.production
```

`web/.env.production` 关键变量（构建时注入，**改了必须重新 build**）：

| 变量 | 生产值 | 说明 |
|---|---|---|
| `VITE_BASE_URL` | `https://你的域名/api` | 后端 API 基地址（须与后端 `APP_URL` 对应） |
| `VITE_APP_NAME` | 后台名称 | |
| `VITE_REVERB_APP_KEY` | 与后端 `REVERB_APP_KEY` 一致 | |
| `VITE_REVERB_HOST` | 你的域名 | |
| `VITE_REVERB_PORT` | `443`（经反代）或 `8080` | |
| `VITE_REVERB_SCHEME` | `https` / `http` | |
| `VITE_WSS_URL` | `wss://你的域名/app` 或 `ws://内网:8080` | Echo 连接地址 |
| `VITE_API_KEY` | 与后端约定的 API 签名密钥 | |

构建：
```bash
yarn install          # 或 npm install
yarn build            # = vite build --mode production → 加载 .env.production，产物在 web/dist
# 类型检查（可选）：yarn build:check
```
> 默认 `outDir` 为 `web/dist`（vite.config.ts 中 `outDir` 一行被注释），后续由 Nginx 直接托管 `web/dist`，**无需改动 vite 配置**。
> 一键打包（可选）：后端也可用 `php artisan catch:build`（或 `catch:build --no-check` 跳过前端 TS 检查）把前后端打成一个 zip，方便整体分发；但生产推荐在服务器分别 `composer install` 与 `yarn build`，避免线上线下环境差异。
> 官方另一种「前后端合并」部署：把 `web/dist` 内容放到后端 `public/admin` 并设 vite `base:'/admin/'`、`outDir:'../public/admin'`，由 Laravel 同一域名 `/admin` 托管；本项目采用单域名根路径托管 SPA（更简单，无需改 vite base），按上文第 6 节即可。

---

## 6. Web 服务器（Nginx）

单 server 块同时托管 SPA 与 API；SPA 在 `web/dist`，API 走 php-fpm，Reverb 经反代为 `wss://域名/app`。

```nginx
server {
    listen 80;
    server_name 你的域名;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name 你的域名;

    ssl_certificate     /etc/nginx/ssl/你的域名/fullchain.pem;
    ssl_certificate_key /etc/nginx/ssl/你的域名/privkey.pem;

    # ---- 后端 Laravel 根目录（API + 静态资源） ----
    root /var/www/telegram-bot/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    client_max_body_size 50m;

    # ---- API：交给 php-fpm ----
    location /api {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass   unix:/run/php/php8.2-fpm.sock;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include        fastcgi_params;
    }

    # ---- 实时广播：反代到 Reverb(:8080) ----
    location /app {
        proxy_pass             http://127.0.0.1:8080;
        proxy_http_version     1.1;
        proxy_set_header       Upgrade $http_upgrade;
        proxy_set_header       Connection "Upgrade";
        proxy_set_header       Host $host;
        proxy_set_header       X-Real-IP $remote_addr;
        proxy_set_header       X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header       X-Forwarded-Proto $scheme;
        proxy_read_timeout     3600s;
        proxy_send_timeout     3600s;
    }

    # ---- 前端 SPA：其余路径回退到 web/dist ----
    location / {
        root /var/www/telegram-bot/web/dist;
        try_files $uri $uri/ /index.html;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?|ttf|eot)$ {
        root /var/www/telegram-bot/web/dist;
        expires 7d;
        add_header Cache-Control "public";
    }
}
```

配置后：`sudo nginx -t && sudo systemctl reload nginx`。

---

## 7. 常驻进程（Supervisor）

需常驻：**Reverb**、**队列 worker**、**Telegram 多会话监听**。
`telegram:scan-activity` 是一次性命令，**不要**放进 supervisor。

`/etc/supervisor/conf.d/telegram-bot.conf`：

```ini
[program:reverb]
command=php /var/www/telegram-bot/artisan reverb:start --host=0.0.0.0 --port=8080
directory=/var/www/telegram-bot
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/telegram-bot/storage/logs/reverb.log
stopwaitsecs=10

[program:queue]
command=php /var/www/telegram-bot/artisan queue:work redis --queue=default --tries=3 --max-time=3600
directory=/var/www/telegram-bot
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/telegram-bot/storage/logs/queue.log
numprocs=2
process_name=%(program_name)s_%(process_num)02d

[program:telegram-listener]
command=php /var/www/telegram-bot/artisan telegram:multi-listen
directory=/var/www/telegram-bot
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/telegram-bot/storage/logs/telegram-listener.log
stopwaitsecs=15
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
sudo supervisorctl status
```

---

## 8. 定时任务

当前 `app/Console/Kernel.php` 调度为空（无 `schedule:` 任务）。如后续新增调度命令，加系统 cron：

```cron
* * * * * cd /var/www/telegram-bot && php artisan schedule:run >> /dev/null 2>&1
```

---

## 9. 上线检查清单

- [ ] PHP 8.2 + 所需扩展 + `tesseract-ocr` 已装
- [ ] `composer install --no-dev` 完成
- [ ] `.env` 已配：`APP_DEBUG=false`、`BROADCAST_DRIVER=reverb`、`QUEUE_CONNECTION=redis`、`CACHE/SESSION_DRIVER=redis`、`REVERB_*` 与前端一致
- [ ] `php artisan catch:install` 已跑（基础安装：user / develop / permissions 模块 + 初始账号）
- [ ] `php artisan catch:module:install cms`、`system`、`telegram` 均已跑（后台内容/系统/Telegram 模块）
- [ ] `storage/app/modules.json` 已生成且含 cms / system / telegram 等全部模块（模块路由/权限依赖它）
- [ ] 初始账号 `catch@admin.com / catchadmin` 已改密码
- [ ] `php artisan telegram:scan-activity` 已执行（注册斜杠命令）
- [ ] `php artisan storage:link` 已建
- [ ] `storage` / `bootstrap/cache` 权限正确
- [ ] 前端 `web/.env` 的 `VITE_BASE_URL` / `VITE_REVERB_*` 指向生产域名，并已 `yarn build`
- [ ] Nginx 配置 `nginx -t` 通过并已 reload
- [ ] Supervisor 三个进程（reverb / queue / telegram-listener）`status` 为 RUNNING
- [ ] 防火墙只开放 80/443；8080/9000/3306/6379 仅内网

> ⚠️ **回归提醒**：工作区目前还有大量与本次功能无关的框架级改动（`config/*`、Cms/Common/Develop/Permissions/System 模块、`ValidateApiKey.php` 等），会随本次部署一起生效，请一并回归测试，避免引入非预期问题。

---

## 10. 升级 / 回滚流程

```bash
cd /var/www/telegram-bot
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate                 # 基础表迁移
php artisan catch:migrate user      # 各模块新增迁移（升级时只跑 pending，已迁移的为 no-op）
php artisan catch:migrate develop
php artisan catch:migrate cms
php artisan catch:migrate system
php artisan catch:migrate telegram  # Telegram 模块（含本次合并/菜单迁移）
php artisan telegram:scan-activity  # 刷新斜杠命令注册
php artisan config:clear && php artisan config:cache   # 改了 .env 时

cd web && yarn install && yarn build

sudo supervisorctl restart all
sudo systemctl reload nginx
```
回滚：切回上一 `git` 提交 + 对应数据库备份恢复 + 重新 `composer install` / `yarn build` + 重启 supervisor。

---

## 11. 本地开发（docker-compose，参考）

仓库 `docker-compose.yml` 提供 PHP(8001) / MySQL(3306) / Node(8000) 三服务：
```bash
docker compose up -d
# php 容器会执行：cp .env.example .env && php artisan catch:install && php artisan serve --port=8001
# node 容器：yarn install && yarn dev （前端开发服务器 :8000）
```
> ⚠️ 注意：`docker-compose.yml` 中 MySQL 镜像为 `mysql:5.7`，**仅作本地开发参考**；生产数据库标准见上文第 2 节（**MySQL 8.0+**）。若本地也要对齐生产，请把该镜像改为 `mysql:8.0`。
> 另外生产**不推荐**用 `php artisan serve`（单进程、无 php-fpm），请按上文 Nginx + php-fpm 部署。
> 🔧 镜像由 5.7 升到 8.0 后，若本地已跑过旧容器并产生过数据卷，**必须先清旧数据卷再起**，否则 5.7→8.0 数据目录不兼容会启动失败：
> ```bash
> docker compose down -v      # 删除容器+匿名数据卷（含 mysql 数据）
> docker compose up -d        # 用新 8.0 镜像重新初始化空库
> ```
> 全新仓库首次部署是空库，不存在此问题；已在用的旧 5.7 库升级前请先 `mysqldump` 备份再导入 8.0。
