# 部署文档：Telegram 机器人后台管理系统

> 最后更新：2026-10-06
> 后端 Laravel 12 + CatchAdmin（PHP 8.2），前端 Vue3 + Vite（目录 `web/`，构建产物 `web/dist`）
> 真实用户（MTProto）能力由 Python `telegram-py` 服务（Telethon）提供，Laravel 通过 HTTP 调用它

---

## 1. 环境要求

| 项 | 要求 |
|---|---|
| 系统 | Ubuntu 22.04 LTS |
| PHP | ≥ 8.2，需扩展：`pdo_mysql` `redis` `zip` `gd` `mbstring` `bcmath` `xml` `curl` `intl` `fileinfo` |
| 数据库 | MySQL ≥ 8.0 |
| 缓存 / 队列 | Redis ≥ 6 |
| Web | Nginx ≥ 1.20 + php-fpm |
| 进程管理 | Supervisor ≥ 4 |
| 前端构建 | Node ≥ 18（yarn / npm） |
| Telegram 账号能力 | Python 3.12 + Telethon（`telegram-py` 服务，端口 8081） |
| 其它 | `tesseract-ocr`（OCR 功能依赖） |

```bash
sudo apt install -y php8.2-cli php8.2-fpm php8.2-mysql php8.2-redis php8.2-zip \
  php8.2-gd php8.2-mbstring php8.2-bcmath php8.2-xml php8.2-curl php8.2-intl \
  nginx redis-server supervisor tesseract-ocr
```

---

## 2. 获取代码

```bash
cd /var/www
git clone https://github.com/layerplay99-cyber/easyTelegramManager.git telegram-bot
cd telegram-bot
# 更新：git pull origin main
```

---

## 3. 后端安装

```bash
composer install --no-dev --optimize-autoloader

cp .env.example .env      # 必须先有 .env，否则安装命令会进入交互式建库流程

# ⚠️ 安装命令有副作用，执行前务必备份这三个文件
cp .env .env.bak
cp config/catch.php config/catch.php.bak
cp composer.json composer.json.bak
# 按第 4 节填好 .env（尤其 DB_*、APP_URL、REVERB_*、TELETHON_*）

# 用 app:install，不要用 catch:install
# ---------------------------------------------------------------
# catch:install 内部以「子进程」执行 `artisan migrate`（见
# vendor/catchadmin/core/src/Commands/InstallCommand.php::publishConfig()）。
# 子进程没有 TTY，而 APP_ENV=production 时 migrate 会要求人工确认，
# 拿不到输入就取消：APPLICATION IN PRODUCTION. Command cancelled.
# 该命令又没有 --force 选项，所以 -it 也救不了（卡住的是子进程）。
# app:install（app/Console/Commands/InstallApp.php）继承原命令，
# 让子进程继承非 production 的 APP_ENV 跳过确认，容器内 / 生产环境可直接跑。
php artisan app:install

# 模块同样要用 app:module:install，不要用 catch:module:install
# ---------------------------------------------------------------
# catch:module:install 流程 = create -> catch:migrate {module} -> catch:db:seed {module}。
# 而 catch:migrate 内部是（MigrateRun.php）：
#     Artisan::call('migrate', ['--path' => $path, '--force' => $this->option('force')]);
# Installer::migrate() 调用它时并没有带 --force，于是 APP_ENV=production 下
# migrate 被安全确认拦截而取消（**不建表**）；但 MigrateRun 不检查返回码，
# 照样打印 "migrate success"，紧接着 seed 访问表就报
#     1146 Table 'xxx_cms_options' doesn't exist
# —— 表象是「表不存在」，实际是迁移被静默取消。
# app:module:install（app/Console/Commands/ModuleInstall.php）让子进程继承
# 非 production 的 APP_ENV 跳过该确认。
php artisan app:module:install permissions

php artisan app:module:install cms
php artisan app:module:install system
php artisan app:module:install telegram

php artisan telegram:scan-activity

php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

装完**必须核对**被强制覆盖的文件并恢复（详见下方注意事项）：

```bash
diff config/catch.php config/catch.php.bak || cp config/catch.php.bak config/catch.php
diff composer.json composer.json.bak   || cp composer.json.bak composer.json
# 若安装失败导致 .env 被删除（命令出错时会 File::delete 掉它）
[ -f .env ] || cp .env.bak .env
php artisan config:clear
```

- `app:install` 已完成：`key:generate`、发布配置、`user` / `develop` 的迁移与 seed、安装 `permissions` 模块。
  **`cms` / `system` / `telegram` 必须再单独安装**，否则后台对应菜单、路由、权限都不会注册。

  模块清单（项目共 7 个模块目录，只有带 `Installer.php` 的能用 `module:install` 安装）：

  | 模块 | 是否有 Installer | 安装方式 |
  |---|---|---|
  | `permissions` | ✅ | `app:install` 内部自动安装；缺失时 `app:module:install permissions` 补 |
  | `cms` | ✅ | `app:module:install cms` |
  | `system` | ✅ | `app:module:install system` |
  | `telegram` | ✅ | `app:module:install telegram` |
  | `user` | ❌（default） | 迁移 + seed 由 `app:install` 内的 `catch:migrate user` / `catch:db:seed user` 完成，**不用** `module:install` |
  | `develop` | ❌（default） | 迁移由 `app:install` 内的 `catch:migrate develop` 完成 |
  | `common` | ❌（default） | 无 `database/` 目录，无迁移，无需操作 |

  `user` / `develop` / `common` 属于 `config('catch.module.default')`，会被 `app:module:install` 的
  已安装检测判定为「已安装」并跳过——这是正常行为，不是漏装。

- 核对已安装模块与表是否齐全：

  ```bash
  php artisan about --only=environment   # 或
  cat storage/app/modules.json           # 已安装模块注册表
  mysql -uroot -p -e "SHOW TABLES FROM bots LIKE 'bot_dayang_%';"
  ```
- 初始账号：`catch@admin.com` / `catchadmin`，上线后立即改密码。
- 模块安装结果写入 `storage/app/modules.json`（不进 git，由命令生成）；丢失后重跑对应
  `php artisan app:module:install {module}` 可重建。
- 若之前误用了 `catch:module:install` 并失败：模块已被写进 `modules.json` 但表没建，
  先 `php artisan catch:migrate {module} --force` 补建表，再跑 `app:module:install {module}`。
- **`app:install` 幂等，可安全重复执行**：核心迁移显示 `Nothing to migrate`、
  已注册模块会「跳过 create，仅补跑迁移与 seed」，不会重建表、不会清空数据，也**不会删除 `.env`**。
- **seed 默认不重复执行**：`app:install` 不带参数时不跑 seed（模块安装流程内部已跑过）。
  需要时显式加 `--seed`。原因是 vendor 缺陷（`SeedRun.php:71-72`）：
  它用 `require_once` 取类名，而 `require_once` 只有首次返回类名、之后返回 `true`，
  于是同一进程内第二次 seed 会 `new true()` 抛
  `Class name must be a valid object or a string`。
  单独执行 `php artisan catch:db:seed {module}`（新进程）则正常。
- ⚠️ **迁移存在跨模块依赖，务必按序执行**。`telegram` 模块的迁移会查询
  `permissions` 表，若 `permissions` 尚未建表就会抛
  `1146 Table 'xxx_permissions' doesn't exist`。
  正确顺序：**`permissions` → `cms` → `system` → `telegram`**。
- ⚠️ **Provider 不得在 boot 阶段依赖未迁移的表**：`ConfigCacheServiceProvider`
  原本在 boot 时直接 `ThirdApiConfig::all()`，全新安装时表尚未创建 → 抛 1146，
  导致**所有** artisan 命令（含 migrate）都无法执行，形成死锁（装模块要 migrate，migrate 又要先 boot provider）。
  已改为先 `Schema::hasTable()` 判断，表不存在就跳过缓存。

---

## 3b. 模块清单与安装顺序

| 模块 | 是否有 `Installer.php` | 安装命令 | 说明 |
|---|---|---|---|
| `permissions` | ✅ | `php artisan app:module:install permissions` | `app:install` 内部会自动装；若后台权限菜单缺失则补跑一次 |
| `cms` | ✅ | `php artisan app:module:install cms` | 内容管理 |
| `system` | ✅ | `php artisan app:module:install system` | 系统管理 |
| `telegram` | ✅ | `php artisan app:module:install telegram` | 本项目核心 |
| `user` | ❌ | **无需 `module:install`** | 属 `config('catch.module.default')`，迁移+seed 由 `app:install` 内的 `catch:migrate user` / `catch:db:seed user` 完成 |
| `develop` | ❌ | **无需 `module:install`** | default 模块，迁移由 `app:install` 内的 `catch:migrate develop` 完成 |
| `common` | ❌ | **无需任何操作** | 无 `database/` 目录，本身没有迁移 |

**注意**：`modules.json` 里看不到 `user` / `develop` / `common` 是**正常现象**——它们是 config 里的默认模块，不进模块注册表，不代表漏装。

**完整安装顺序**：

```bash
cd /var/www/easyTelegramManager

# 1) 核心安装（含 permissions 模块、user/develop 的 migrate+seed）
php artisan app:install

# 2) 四个带 Installer 的模块（app:install 已装 permissions，这里补跑其余）
php artisan app:module:install cms
php artisan app:module:install system
php artisan app:module:install telegram

# 3) 一次性命令
php artisan telegram:scan-activity

# 4) 收尾
php artisan storage:link
php artisan config:clear
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

**核对是否装全**：

```bash
# 已安装模块注册表（应有 permissions / cms / system / telegram）
cat storage/app/modules.json

# 表是否齐全
mysql -uroot -p -e "SHOW TABLES FROM bots LIKE 'bot_dayang_%';"

# 确认没有漏掉 permissions 的表
mysql -uroot -p -e "SHOW TABLES FROM bots LIKE 'bot_dayang_%permission%';"
```
- ⚠️ `app:install`（原 `catch:install`）会用 vendor 版本**强制覆盖 `config/catch.php`**（`vendor:publish --tag=catch-config --force`），
  本项目对该文件的定制（`LocaleMiddleware`、`listen_db_log=false`、`super_admin` 走 env）会被还原成框架默认值。
  装完请确认该文件仍是仓库版本，若被覆盖需恢复并 `php artisan config:clear`。
- 部署**不要用 `--reinstall`**（会 DROP 整个数据库）。
- ⚠️ **长表前缀会导致 MySQL 索引名超长**：索引名上限 64 字符，而 Laravel 自动生成的索引名
  = `{表前缀}{表名}_{列1}_{列2}_index|_unique`。生产库前缀 `bot_dayang_`（11 字符）较长，
  已踩过两处并修复（均改为显式短索引名）：
  - `database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php`
    （不用 `morphs()`，改 `pat_tokenable_index`，原名 67 字符）
  - `modules/Telegram/.../2025_01_27_000003_create_exchange_rates_table.php`
    （`uk_exchange_rate`，原名 69 字符）
  新增多列索引时务必显式命名并核算长度；报错特征：`SQLSTATE[42000] 1059 Identifier name ... is too long`。
  若反复踩，最彻底的办法是缩短 `DB_PREFIX`（如 `bd_`）后重建表。
- 仓库需带 `web/` 目录，缺失时 `app:install` 会尝试从 Gitee 克隆前端。

---

## 4. `.env` 关键配置

| 变量 | 生产取值 |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://bot.dg178.top` |
| `DB_HOST` `DB_PORT` `DB_DATABASE` `DB_USERNAME` `DB_PASSWORD` `DB_PREFIX` | 实际 MySQL（前缀沿用 `bm`） |
| `BROADCAST_DRIVER` | `reverb` |
| `QUEUE_CONNECTION` | `redis` |
| `CACHE_DRIVER` / `SESSION_DRIVER` | `redis` / `redis` |
| `REDIS_HOST` `REDIS_PORT` `REDIS_PASSWORD` | 实际 Redis |
| `REVERB_APP_ID` `REVERB_APP_KEY` `REVERB_APP_SECRET` | 与前端 `VITE_REVERB_*` 一致 |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | `botwss.dg178.top` / `443` / `https` |
| `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` | `botmg.dg178.top` / `.dg178.top` |
| `EXTERNAL_API_KEY` | 与前端 `VITE_API_KEY` 一致 |
| `MAIL_*` / `AWS_*` / `ALIOSS_*` / `OPENAI_*` | 按需填写 |

---

## 5. 前端构建

`VITE_*` 在**构建时**注入，改了必须重新 build。

```bash
cd web
cp .env.example .env.production
```

`web/.env.production`：

| 变量 | 生产取值 |
|---|---|
| `VITE_BASE_URL` | `https://bot.dg178.top/api` |
| `VITE_APP_NAME` | 后台名称 |
| `VITE_REVERB_APP_KEY` | 与后端 `REVERB_APP_KEY` 一致 |
| `VITE_REVERB_HOST` / `VITE_REVERB_PORT` / `VITE_REVERB_SCHEME` | `botwss.dg178.top` / `443` / `https` |
| `VITE_WSS_URL` | `wss://botwss.dg178.top/app` |
| `VITE_API_KEY` | 与后端 `EXTERNAL_API_KEY` 一致 |

```bash
yarn install
yarn build          # = vite build --mode production，产物 web/dist
```

---

## 6. Nginx

单 server 块托管 SPA（`/`）+ API（`/api`）+ Reverb 反代（`/app`）。

```nginx
server {
    listen 80;
    server_name bot.dg178.top;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name bot.dg178.top;

    ssl_certificate     /etc/nginx/ssl/bot.dg178.top/fullchain.pem;
    ssl_certificate_key /etc/nginx/ssl/bot.dg178.top/privkey.pem;

    root /var/www/telegram-bot/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    client_max_body_size 50m;

    location /api {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass   unix:/run/php/php8.2-fpm.sock;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include        fastcgi_params;
    }

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

```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## 7. 常驻进程（Supervisor）

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

; ⚠️ [已废弃] Telegram 常驻监听改由 Python Telethon 服务（`telegram-py` 容器）负责。
; 不要再启动下面这个 program —— PHP(MadelineProto) 与 Python(Telethon) 同时监听会
; 重复处理同一条消息/事件，导致表情与群成员数据重复落库。
; docker compose 部署时 telegram-py 会自动启动；旧的 php 版 telegram 服务已加
; profiles:["madeline-legacy"]，默认不启动（docker compose up -d 不会带起它）。
;
; [program:telegram-listener]
; command=php /var/www/telegram-bot/artisan telegram:multi-listen
; directory=/var/www/telegram-bot
; user=www-data
; autostart=true
; autorestart=true
; redirect_stderr=true
; stdout_logfile=/var/www/telegram-bot/storage/logs/telegram-listener.log
; stopwaitsecs=15
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
sudo supervisorctl status
```

`telegram:scan-activity` 是一次性命令，不要放进 Supervisor。

---

## 8. 定时任务

```cron
* * * * * cd /var/www/telegram-bot && php artisan schedule:run >> /dev/null 2>&1
```

---

## 9. 升级

```bash
cd /var/www/telegram-bot
git pull origin main
composer install --no-dev --optimize-autoloader

# production 下必须加 --force，否则 migrate 会被安全确认拦截而静默跳过
#（catch:migrate 甚至会打印 "migrate success" 但实际没执行，见第 3 节说明）
php artisan migrate --force
php artisan catch:migrate user --force
php artisan catch:migrate develop --force
php artisan catch:migrate cms --force
php artisan catch:migrate system --force
php artisan catch:migrate telegram --force
php artisan telegram:scan-activity

php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache

cd web && yarn install && yarn build

sudo supervisorctl restart all
sudo systemctl reload nginx
```

回滚：`git checkout` 上一提交 + 恢复数据库备份 + 重新 `composer install` / `yarn build` + 重启 Supervisor。

---

## 10. 上线检查清单

- [ ] PHP 8.2 扩展齐全、`tesseract-ocr` 已装
- [ ] `.env` 已配：`APP_DEBUG=false`、`BROADCAST_DRIVER=reverb`、`QUEUE_CONNECTION=redis`、`REVERB_*` 与前端一致
- [ ] `app:install` 已跑（容器内 / production 不要用 `catch:install`），初始账号已改密码
- [ ] 被覆盖的 `config/catch.php` / `composer.json` 已按备份恢复，`.env` 仍在（未被安装命令删掉）
- [ ] `permissions` / `cms` / `system` / `telegram` 四个带 Installer 的模块均已安装（前三个由 `app:install`+手工补，见第 3 节）
- [ ] `user` / `develop` 的迁移已执行（`app:install` 内部完成）；`common` 无迁移
- [ ] 各模块的表确实建出来了（`SHOW TABLES FROM bots LIKE 'bot_dayang_%'` 核对），没出现 `1146 Table doesn't exist`
- [ ] `storage/app/modules.json` 内容与预期模块一致
- [ ] `telegram:scan-activity` 已执行
- [ ] `storage:link` 已建，`storage` / `bootstrap/cache` 属主为 `www-data`
- [ ] 前端 `web/.env.production` 指向生产域名，已 `yarn build`
- [ ] `nginx -t` 通过并已 reload
- [ ] Supervisor 进程 RUNNING（`reverb` + `queue` 两个；`telegram-listener` 已废弃，改由 `telegram-py` 负责）
- [ ] `telegram-py` 容器 Up，`curl http://telegram-py:8081/health` 返回 `{"ok":true}`
- [ ] 旧的 `tgbot-telegram` 容器**未运行**（`docker ps` 里不应出现），避免事件双处理
- [ ] 防火墙只开 80/443；8080 / 9000 / 3306 / 6379 仅内网
