# 部署文档：Telegram 机器人后台管理系统

> 最后更新：2026-10-16
> 相关文档：[Telegram 模块开发](telegram-module.md) · [数据库说明](database.md)
> 后端 Laravel 12 + CatchAdmin（PHP 8.2），前端 Vue3 + Vite（目录 `web/`，产物 `web/dist`）
> 真实用户（MTProto）能力由 Python `telegram-service`（Telethon）提供，Laravel 通过 HTTP 调用它

---

## 0. 两种部署方式怎么选

| | Docker 方式（推荐） | 普通方式（宿主机） |
|---|---|---|
| 组成 | 共享栈 `docker-stack/`（mysql/redis/nginx/php/node）+ 本项目 `docker-compose.yml` 的 app 容器 | 宿主机直接装 PHP/MySQL/Redis/Nginx/Supervisor |
| 适合 | 开发、测试，以及希望环境可复现的部署 | 已有 LNMP 环境、要求直接管进程的生产机 |
| 入口 | `http://tgbot.local`（开发机 `/etc/hosts` 指向宿主） | 自己配域名 + HTTPS |
| 迁移怎么跑 | `docker exec tgbot-php php artisan catch:migrate xxx --force` | 直接在项目目录 `php artisan catch:migrate xxx --force` |
| 前端 | 部署时执行一次 `docker exec tgbot-frontend sh -c "yarn install && yarn build"`，产物进 `web/dist`（容器本身只装依赖后挂住，不常驻编译） | 宿主机 `yarn build` |

> 两种方式**后端安装步骤完全一致**（`app:install` + 模块安装），差别只在"命令在哪跑、
> 基础设施谁提供"。第 3 节之后的通用章节（`.env` / 前端 / 上线检查）对两者都适用。

---

## 1. 环境要求

| 项 | 要求 |
|---|---|
| 系统 | Ubuntu 22.04 LTS |
| PHP | ≥ 8.2，扩展：`pdo_mysql` `redis` `zip` `gd` `mbstring` `bcmath` `xml` `curl` `intl` `fileinfo` |
| 数据库 | MySQL ≥ 8.0 |
| 缓存 / 队列 | Redis ≥ 6 |
| Web | Nginx ≥ 1.20 + php-fpm |
| 进程管理 | Supervisor ≥ 4（普通方式必需） |
| 前端构建 | Node ≥ 18（yarn / npm） |
| Docker 方式 | Docker ≥ 24 + Compose v2 |
| Telegram 账号能力 | Python 3.12 + Telethon（`telegram-service`，端口 8081） |
| 其它 | `tesseract-ocr`（OCR 功能依赖） |

```bash
# 普通方式装依赖
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

> 仓库需带 `web/` 目录，缺失时 `app:install` 会尝试从 Gitee 克隆前端。

---

## 3. Docker 方式

### 3.1 架构

```
/opt/docker-stack/          共享栈（mysql / redis / nginx / php / node 基础镜像）
/opt/projects/<项目>/        项目代码（两种方式的 PROJECTS_ROOT 指到这里）
        │
        └─ 本项目 docker-compose.yml 只跑自己的 app 容器，接入共享栈的 stack 网络
```

**本项目容器清单**（`docker-compose.yml`，`name: telegram-bot`）：

| 容器 | 镜像 | 作用 |
|---|---|---|
| `tgbot-php` | `telegram-bot-php:8.4` | php-fpm，跑 artisan / 接收 webhook |
| `tgbot-frontend` | `telegram-bot-node:20` | 装依赖后挂住；构建由部署时执行一次 `yarn build`，产出 `web/dist` |
| `tgbot-queue` | `telegram-bot-php:8.4` | 队列 worker |
| `tgbot-reverb` | `telegram-bot-php:8.4` | WebSocket（`reverb:start`，8080） |
| `tgbot-telethon` | `telegram-bot-telethon`（本地构建） | Python Telethon 服务，8081 |
| `tgbot-telegram` | 同 php | **默认不启动**（`profiles: ["madeline-legacy"]`），旧 MadelineProto 监听，避免事件双处理 |
| `tgbot-bot-api` | `aiogram/telegram-bot-api` | **可选**：本地 TDLib Bot API 服务，降延迟 + 突破文件大小限制，见 [telegram-bot-api-server.md](telegram-bot-api-server.md) |
| `tgbot-redis` | `redis:7-alpine` | 项目自带 Redis（与共享栈的 redis 解耦） |
| `tgbot-mysql` | `mysql:8.0` | 本机测试库（容器名以实际部署为准） |

共享栈负责 `mysql` / `redis` / `pgsql` / `nginx` / `node` 常驻环境，详见
`docker-stack/README.md`。

### 3.2 部署共享栈

```bash
sudo mkdir -p /opt/docker-stack /opt/projects
sudo cp -r docker-stack/. /opt/docker-stack/

cd /opt/docker-stack
cp .env.example .env          # 设 STACK_ROOT / PROJECTS_ROOT（与项目 .env 一致！）
docker compose build php node
docker compose up -d mysql redis pgsql nginx node
docker compose ps
```

> 共享栈的 `nginx` 按域名路由：项目 vhost 放 `conf/nginx/sites/`，
> 模板见 `conf/nginx/sites/_template.conf`。

### 3.3 部署本项目

```bash
# 1. 代码放到共享区（保留目录名）
sudo mkdir -p /opt/projects/telegram-bot
sudo cp -r /path/to/telegram-bot/. /opt/projects/telegram-bot/

cd /opt/projects/telegram-bot

# 2. 项目 .env（与共享栈对齐）
cp .env.example .env
```

`.env` 里与 Docker 相关的关键项：

```bash
APP_URL=http://tgbot.local
DB_HOST=mysql          DB_PORT=3306     DB_DATABASE=bots
DB_USERNAME=bots       DB_PASSWORD=bots123456
REDIS_HOST=redis       REDIS_PORT=6379
REVERB_HOST=tgbot.local
# 项目代码位置（两处 PROJECTS_ROOT 必须一致！）
PROJECTS_ROOT=/var/www                # 项目代码所在的根目录
PROJECT_DIR=easyTelegramManager       # 本项目在 PROJECTS_ROOT 下的目录名
```

> 项目不必非得放 `/opt/projects` 下。只要「共享栈 `.env` 的 `PROJECTS_ROOT`」和
> 「项目 `.env` 的 `PROJECTS_ROOT`」指向同一个父目录，且 `PROJECT_DIR` 等于项目实际目录名，
> 挂载与 nginx 的 `SCRIPT_FILENAME` 就能对上。

```bash
# 3. 启动本项目容器
docker compose up -d

# 4. 本机解析域名，浏览器打开 http://tgbot.local
#    127.0.0.1 tgbot.local
```

### 3.4 后端安装（容器内）

⚠️ **在 php 容器里跑，不要用宿主机的 php**：

```bash
docker exec -w /var/www tgbot-php php artisan app:install
docker exec -w /var/www tgbot-php php artisan app:module:install permissions
docker exec -w /var/www tgbot-php php artisan app:module:install cms
docker exec -w /var/www tgbot-php php artisan app:module:install system
docker exec -w /var/www tgbot-php php artisan app:module:install telegram
docker exec -w /var/www tgbot-php php artisan storage:link
docker exec -w /var/www tgbot-php php artisan config:clear
```

> `-w /var/www` 指定工作目录；若项目挂在别处，用容器内的 `working_dir`
> （见 `docker-compose.yml` 的 `working_dir`，默认 `/opt/projects/${PROJECT_DIR}`）。

### 3.5 常用操作

```bash
# 迁移（production 必须 --force，否则被安全确认静默跳过）
docker exec -w /var/www tgbot-php php artisan catch:migrate telegram --force

# 看日志
docker logs -f tgbot-php
docker logs -f tgbot-queue
tail -f storage/logs/laravel-*.log

# 重启某个容器
docker compose restart queue reverb

# 进容器
docker exec -it tgbot-php sh

# 全量重建（慎）
docker compose down -v && docker compose up -d
```

### 3.6 升级

```bash
cd /opt/projects/telegram-bot
git pull origin main

docker exec -w /var/www tgbot-php php artisan catch:migrate telegram --force
docker exec -w /var/www tgbot-php php artisan catch:migrate cms --force
docker exec -w /var/www tgbot-php php artisan catch:migrate system --force
docker exec -w /var/www tgbot-php php artisan config:clear
# 前端要手动构建一次（容器不再常驻 build --watch）
docker exec tgbot-frontend sh -c "yarn install && yarn build"
docker compose restart queue queue-ocr reverb
```

### 3.7 Docker 排错

| 现象 | 原因 / 处理 |
|---|---|
| `The "PROJECTS_ROOT" variable is not set` | 项目 `.env` 缺 `PROJECTS_ROOT` / `PROJECT_DIR`（被 gitignore 容易漏） |
| `pull access denied for stack-php` / `No such image: stack-php:8.4` | 公共镜像没构建：共享栈先 `docker compose build php node` 再 `up -d` |
| 502 / `Primary script unknown` | nginx 的 `SCRIPT_FILENAME` 与 php 容器挂载路径不一致，核对两边 `PROJECTS_ROOT`/`PROJECT_DIR` 与 vhost 里的 `/opt/projects/<PROJECT_DIR>/public` |
| php 容器启动即退出 | 用的是 `image:` 不是 `build:`，不会自己构建，必须先有 `stack-php:8.4` |
| 前端 500 循环重定向 | nginx 用 `try_files` 读静态、但前端跑的是 `yarn dev`（没有 `web/dist`）。本项目容器跑的是 `build --watch`，等 `built in xxxms` 出现再访问 |
| `spawn xdg-open ENOENT` | `vite.config.ts` 的 `open: true` 在容器里找不到桌面环境，无害；compose 已设 `BROWSER: none` |
| webhook 推队列失败、群不同步 | `REDIS_HOST=redis` 解析到的不是本项目 `tgbot-redis`；确认共享栈的 `stack-redis` 已停用，释放 `stack` 网络上的 `redis` 别名 |
| Telethon 回调 404 | `LARAVEL_HOST` 要与共享栈 nginx 的 `server_name` 一致（默认 `tgbot.local`） |

---

## 4. 普通方式（宿主机）

### 4.1 后端安装

```bash
composer install --no-dev --optimize-autoloader

cp .env.example .env      # 必须先有 .env，否则安装命令会进入交互式建库流程

# ⚠️ 安装命令有副作用，执行前务必备份这三个文件
cp .env .env.bak
cp config/catch.php config/catch.php.bak
cp composer.json composer.json.bak
# 按第 5 节填好 .env（尤其 DB_*、APP_URL、REVERB_*、TELETHON_*）

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

php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

装完**必须核对**被强制覆盖的文件并恢复：

```bash
diff config/catch.php config/catch.php.bak || cp config/catch.php.bak config/catch.php
diff composer.json composer.json.bak   || cp composer.json.bak composer.json
# 若安装失败导致 .env 被删除（命令出错时会 File::delete 掉它）
[ -f .env ] || cp .env.bak .env
php artisan config:clear
```

- `app:install` 已完成：`key:generate`、发布配置、`user` / `develop` 的迁移与 seed、安装 `permissions` 模块。
  **`cms` / `system` / `telegram` 必须再单独安装**，否则后台对应菜单、路由、权限都不会注册。
- ⚠️ `app:install` 会用 vendor 版本**强制覆盖 `config/catch.php`**
  （`vendor:publish --tag=catch-config --force`），本项目对该文件的定制
  （`LocaleMiddleware`、`listen_db_log=false`、`super_admin` 走 env）会被还原成框架默认值。
  装完确认该文件仍是仓库版本，被覆盖就恢复并 `config:clear`。
- 部署**不要用 `--reinstall`**（会 DROP 整个数据库）。

### 4.2 模块清单与安装顺序

| 模块 | 是否有 `Installer.php` | 安装命令 | 说明 |
|---|---|---|---|
| `permissions` | ✅ | `php artisan app:module:install permissions` | `app:install` 内部会自动装；若后台权限菜单缺失则补跑一次 |
| `cms` | ✅ | `php artisan app:module:install cms` | 内容管理 |
| `system` | ✅ | `php artisan app:module:install system` | 系统管理 |
| `telegram` | ✅ | `php artisan app:module:install telegram` | 本项目核心 |
| `user` | ❌（default） | **无需 `module:install`** | 迁移+seed 由 `app:install` 内的 `catch:migrate user` / `catch:db:seed user` 完成 |
| `develop` | ❌（default） | **无需 `module:install`** | 迁移由 `app:install` 内的 `catch:migrate develop` 完成 |
| `common` | ❌（default） | **无需任何操作** | 无 `database/` 目录，本身没有迁移 |

**注意**：`modules.json` 里看不到 `user` / `develop` / `common` 是**正常现象**——
它们是 config 里的默认模块，不进模块注册表，不代表漏装。

**完整安装顺序**：

```bash
cd /var/www/telegram-bot

# 1) 核心安装（含 permissions 模块、user/develop 的 migrate+seed）
php artisan app:install

# 2) 三个带 Installer 的模块
php artisan app:module:install cms
php artisan app:module:install system
php artisan app:module:install telegram

# 3) 收尾
php artisan storage:link
php artisan config:clear
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

**核对是否装全**：

```bash
cat storage/app/modules.json          # 已安装模块注册表
mysql -uroot -p -e "SHOW TABLES FROM bots LIKE 'bm%';"
mysql -uroot -p -e "SHOW TABLES FROM bots LIKE 'bm%permission%';"
```

- 初始账号：`catch@admin.com` / `catchadmin`，上线后立即改密码。
- 模块安装结果写入 `storage/app/modules.json`（不进 git）；丢失后重跑对应
  `php artisan app:module:install {module}` 可重建。
- 若之前误用了 `catch:module:install` 并失败：模块已被写进 `modules.json` 但表没建，
  先 `php artisan catch:migrate {module} --force` 补建表，再跑 `app:module:install {module}`。
- **`app:install` 幂等，可安全重复执行**：核心迁移显示 `Nothing to migrate`、
  已注册模块会「跳过 create，仅补跑迁移与 seed」，不会重建表、不会清空数据，也**不会删除 `.env`**。
- **seed 默认不重复执行**：`app:install` 不带参数时不跑 seed（模块安装流程内部已跑过）。
  原因是 vendor 缺陷（`SeedRun.php:71-72`）：它用 `require_once` 取类名，而 `require_once`
  只有首次返回类名、之后返回 `true`，于是同一进程内第二次 seed 会 `new true()` 抛
  `Class name must be a valid object or a string`。单独执行 `php artisan catch:db:seed {module}`（新进程）则正常。
- ⚠️ **迁移存在跨模块依赖，务必按序执行**：`telegram` 的迁移会查 `permissions` 表，
  若 `permissions` 尚未建表就会抛 `1146 Table 'xxx_permissions' doesn't exist`。
  正确顺序：**`permissions` → `cms` → `system` → `telegram`**。
- ⚠️ **Provider 不得在 boot 阶段依赖未迁移的表**：`ConfigCacheServiceProvider` 原本在 boot 时
  直接 `ThirdApiConfig::all()`，全新安装时表尚未创建 → 抛 1146，导致**所有** artisan 命令
  （含 migrate）都无法执行，形成死锁。已改为先 `Schema::hasTable()` 判断，表不存在就跳过缓存。

---

## 5. `.env` 关键配置

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
| `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` | 见 5.1 |
| `EXTERNAL_API_KEY` | 与前端 `VITE_API_KEY` 一致 |
| `TELETHON_CALLBACK_TOKEN` | 与 `telegram-service` 的 `CALLBACK_TOKEN` 必须一致 |
| `MAIL_*` / `AWS_*` / `ALIOSS_*` / `OPENAI_*` | 按需填写 |

### 5.1 `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` 怎么配

> ⚠️ **这两个变量与容器间通信无关**。
> 容器内部（PHP ↔ MySQL / Redis / `telegram-py`）走 Docker 网络服务名（`mysql`、`redis`、`telegram-py:8081`），
> 不经过浏览器 cookie，因此这两个变量**完全不参与**。
> 它们只影响 **浏览器 → Nginx → PHP** 这一跳（依据请求头的 Origin / Host 判定）。

| 变量 | 含义 | 取值规则 |
|---|---|---|
| `SANCTUM_STATEFUL_DOMAINS` | 哪些来源的请求走 **cookie** 认证（stateful） | 浏览器地址栏的**域名**，不含协议；端口非 80/443 时要带端口 |
| `SESSION_DOMAIN` | session cookie 的 domain | 带前导点（`.dg178.top`）= 允许**子域共享**；单域名用不带点的完整域名更精确安全 |

**单域名方案（前端与 API 同域，推荐）**：

```bash
SANCTUM_STATEFUL_DOMAINS=bot.dg178.top
SESSION_DOMAIN=bot.dg178.top
```

docker 开发环境（入口 `tgbot.local`）则改为 `tgbot.local`。

**例外**：若前端 dev server 直连（`:5173`）跨端口访问 API，属于跨源，需：

```bash
SANCTUM_STATEFUL_DOMAINS=bot.dg178.top,localhost:5173,127.0.0.1:5173
```

并同步配置 `config/cors.php`：`allowed_origins` 包含这些源、`supports_credentials => true`。

### 5.2 密钥字段清单（模板里是空的，部署时必填）

`.env.example` 里密钥一律留空是**有意为之**（模板进 git，写密钥等于泄露）。

| 变量 | 来源 / 约束 |
|---|---|
| `APP_KEY` | `php artisan key:generate` 自动生成 |
| `EXTERNAL_API_KEY` | 与前端 `VITE_API_KEY` **必须一致** |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | 与前端 `VITE_REVERB_*` 一致 |
| `TELETHON_CALLBACK_TOKEN` | 与 `telegram-service` 的 `CALLBACK_TOKEN` **必须一致** |
| `DB_PASSWORD` / `REDIS_PASSWORD` | 按共享栈实际凭证 |

---

## 6. 前端构建

### 6.1 `web/` 下有好几个 env 文件，该复制哪个？

`VITE_*` 在**构建时**注入（不是运行时），改了必须重新 `yarn build`。

Vite 按 **mode** 加载不同文件，同名 key 以高优先级为准：

| 命令 | mode | 加载顺序（后者覆盖前者） |
|---|---|---|
| `yarn dev` | development | `.env.development` > `.env` |
| `yarn build` | production | `.env.production` > `.env` |

| 文件 | 用途 | 是否入库 |
|---|---|---|
| `web/.env` | 兜底默认值，两个 mode 都会读 | ❌ 已忽略（含真实 KEY） |
| `web/.env.development` | **本地开发**（`yarn dev` 读），走共享栈 `http://tgbot.local/api` | ❌ 已忽略（含真实 KEY） |
| `web/.env.development.example` | 本地开发模板，直连 `127.0.0.1:8088` | ✅ 入库 |
| `web/.env.example` | **生产构建**模板 → 复制成 `.env.production` | ✅ 入库 |
| `web/.env.production` | 生产构建实际读取的文件 | ❌ 已忽略 |

- **本地开发**：直接用仓库里现成的 `web/.env.development`，**不用复制任何文件**。
- **生产构建**：`cp .env.example .env.production` 后按下表填写。

> ⚠️ 别把 `web/.env.development` 拷到生产 —— 它指向 `tgbot.local` 且是明文 KEY。

#### 单域名 + path 区分（推荐，当前方案）

前后端共用一个域名，靠 path 分流：

| path | 去向 |
|---|---|
| `/` | 前端页面（`web/dist` 静态，或 dev 模式下反代 vite :5173） |
| `/api` | 后端接口（php-fpm） |
| `/app` | Reverb WebSocket（反代到 `tgbot-reverb:8080`） |

| 变量 | HTTP 取值 | HTTPS 取值 |
|---|---|---|
| `VITE_BASE_URL` | `http://<域名>/api` | `https://<域名>/api` |
| `VITE_REVERB_HOST` | `<域名>` | `<域名>` |
| `VITE_REVERB_PORT` | **`80`** | **`443`** |
| `VITE_REVERB_SCHEME` | `http` | `https` |
| `VITE_REVERB_USE_TLS` | `false` | `true` |
| `VITE_WSS_URL` | `ws://<域名>/app` | `wss://<域名>/app` |

> ⚠️ **最常见的坑**：`VITE_REVERB_PORT` 要填 **Nginx 的端口（80/443）**，
> 不是 Reverb 的 `8080` —— WebSocket 走的是 Nginx 的 `/app` 反代。

### 6.2 构建

```bash
cd web
yarn install
yarn build          # = vite build --mode production，产物 web/dist
```

#### ⚠️ `yarn dev` 不会产出 dist（500 循环的根因）

| 模式 | 命令 | 产物 | nginx 该怎么配 |
|---|---|---|---|
| **开发服务器** | `yarn dev --host 0.0.0.0 --port 5173` | **无 dist**（仅内存编译，带 HMR） | `location /` 必须**反代**到 `http://tgbot-frontend:5173` |
| **构建模式**（Docker 当前） | `yarn build`（部署时执行一次） | **产出 `web/dist`** | `location /` 用 `root .../web/dist` + `try_files` |
| **构建 + 监听** | `yarn build --watch` | 产出 `web/dist`，但常驻一个 node 进程持续编译 | 同上；内存紧张的机器别用 |

若 nginx 用 `try_files $uri $uri/ /index.html` 读静态文件、但前端跑的是 `yarn dev`（dist 不存在），就会触发：

```
[error] rewrite or internal redirection cycle while internally redirecting to "/index.html"
```

→ nginx 返回 **500**。判断方法：

```bash
ls -l /var/www/telegram-bot/web/dist/index.html   # 不存在就是没构建
```

首次构建约需 **90 秒**，期间 `dist/index.html` 尚不存在，访问会 500 —— 等 `built in xxxms` 出现后再访问。
Docker 方式下前端**不常驻编译**：容器起来只 `yarn install`，构建由 `./deploy.sh app`（或手动
`docker exec tgbot-frontend sh -c "yarn install && yarn build"`）执行一次。改了前端代码要重新构建才生效。

#### 前端容器启动日志中的「无害噪声」

| 日志 | 说明 |
|---|---|
| `Error: spawn xdg-open ENOENT` | `vite.config.ts` 的 `open: true` 想自动开浏览器，容器内无桌面环境。compose 已设 `BROWSER: none` 消除 |
| `Browserslist: caniuse-lite is 13 months old` | 兼容性数据过期，不影响构建。可选：`npx update-browserslist-db@latest` |
| `YN0060` / `YN0002`（yarn） | peer 依赖版本不匹配警告，非致命 |

**判断前端是否真的起来了**，看这一行：

```
VITE v6.x.x  ready in xxx ms
➜  Local:   http://localhost:5173/
```

构建后注意：

- 产物在 `web/dist`，Nginx 的 `root` 需指向它。
- 改了任何 `VITE_*` 都要**重新 `yarn build`**。
- `VITE_API_KEY` 必须与后端 `.env` 的 `EXTERNAL_API_KEY` 一致，否则外部 API 调用签名校验失败。
- `VITE_REVERB_*` 与后端 `.env` 的 `REVERB_*` 保持一致，否则 WebSocket 连不上。

---

## 7. Nginx

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

Docker 方式下这份配置**由 `deploy.sh` 生成**：`/opt/docker-stack/conf/nginx/sites/<项目目录名>.conf`
（`server_name` = 真实域名，`location /` 托管 `web/dist`，`/api` 走 php-fpm，`/app` 反代 reverb），
改完 `deploy.env` 重跑 `./deploy.sh app` 即可，脚本会自动 `nginx -t` 并 reload。

仓库里 `docker-stack/conf/nginx/sites/` 下的都是模板：
`_template.conf.example`（通用骨架）、`_dev.conf.example`（本机开发，server_name tgbot.local，
前端反代 5173 dev server）。带 `.example` 后缀不会被 `sites/*.conf` 加载。

---

## 8. 常驻进程（Supervisor，普通方式）

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

; ⚠️ [已废弃] Telegram 常驻监听改由 Python Telethon 服务（telegram-py 容器）负责。
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

**Docker 方式**：队列 / Reverb / Telethon 都是 compose 里的常驻容器，不用 Supervisor。

---

## 9. 定时任务

```cron
* * * * * cd /var/www/telegram-bot && php artisan schedule:run >> /dev/null 2>&1
```

Docker 方式下在 `tgbot-php` 容器里配，或宿主 cron `docker exec`：

```cron
* * * * * docker exec -w /var/www tgbot-php php artisan schedule:run >> /dev/null 2>&1
```

---

## 10. 升级与回滚

### 普通方式

```bash
cd /var/www/telegram-bot
git pull origin main
composer install --no-dev --optimize-autoloader

# production 下必须加 --force，否则 migrate 会被安全确认拦截而静默跳过
#（catch:migrate 甚至会打印 "migrate success" 但实际没执行，见 4.1 说明）
php artisan migrate --force
php artisan catch:migrate user --force
php artisan catch:migrate develop --force
php artisan catch:migrate cms --force
php artisan catch:migrate system --force
php artisan catch:migrate telegram --force

php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache

cd web && yarn install && yarn build

sudo supervisorctl restart all
sudo systemctl reload nginx
```

回滚：`git checkout` 上一提交 + 恢复数据库备份 + 重新 `composer install` / `yarn build` + 重启 Supervisor。

### Docker 方式

见 3.6。

> 本项目**不需要为普通功能改动跑额外命令**：新增回调转换器 / 执行器都是**实时扫描目录**，
> 不改表结构、不清缓存；只有加了迁移才需要 `catch:migrate xxx --force`。

---

## 11. 上线检查清单

**通用**

- [ ] PHP 8.2 扩展齐全、`tesseract-ocr` 已装（Docker 方式由镜像提供）
- [ ] `.env` 已配：`APP_DEBUG=false`、`BROADCAST_DRIVER=reverb`、`QUEUE_CONNECTION=redis`
- [ ] `APP_KEY` / `EXTERNAL_API_KEY` / `REVERB_*` / `TELETHON_CALLBACK_TOKEN` 与前端、Python 服务一致
- [ ] `app:install` 已跑（**不要用 `catch:install`**），初始账号已改密码
- [ ] 被覆盖的 `config/catch.php` / `composer.json` 已按备份恢复，`.env` 仍在
- [ ] `permissions` / `cms` / `system` / `telegram` 四个模块均已安装
- [ ] 表都建出来了（`SHOW TABLES FROM bots LIKE 'bm%'`），没出现 `1146 Table doesn't exist`
- [ ] `storage/app/modules.json` 内容与预期模块一致
- [ ] `storage:link` 已建，`storage` / `bootstrap/cache` 属主为 `www-data`
- [ ] 前端已指向生产域名并 `yarn build`，`web/dist/index.html` 存在
- [ ] `nginx -t` 通过并已 reload
- [ ] 防火墙只开 80/443；8080 / 9000 / 3306 / 6379 仅内网

**普通方式专有**

- [ ] Supervisor 进程 RUNNING（`reverb` + `queue`；`telegram-listener` 已废弃，改由 `telegram-py` 负责）
- [ ] cron 里已加 `schedule:run`

**Docker 方式专有**

- [ ] 共享栈 `mysql` / `redis` / `nginx` 已起，`stack-php:8.4` / `stack-node:20` 已构建
- [ ] 项目 `.env` 的 `PROJECTS_ROOT` / `PROJECT_DIR` 与共享栈一致
- [ ] `docker compose ps` 里 `tgbot-php` / `tgbot-frontend` / `tgbot-queue` / `tgbot-reverb` / `tgbot-telethon` 全部 Up
- [ ] 旧的 `tgbot-telegram` 容器**未运行**（默认在 `madeline-legacy` profile 里），避免事件双处理
- [ ] `curl http://telegram-py:8081/health` 返回 `{"ok":true}`（在 stack 网络内）

---

## 12. 商户（租户）与三方入站密钥

### 12.1 迁移

`2026_11_06_000001_create_merchants_and_tenant_columns.php` 会建 `merchants` 表、给 `users` / `roles` / `departments` / `jobs` 与全部 Telegram 业务表加 `tenant_id`、给 `thirdapi_config` 加密钥对字段。走模块迁移：

```
php artisan catch:migrate telegram --force
php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
```

新加了后台路由（`telegram/merchants`、`third/config/{id}/keys`）和中间件别名，**部署时必须重跑整套缓存命令**，否则 `route_not_found_or_register`。

### 12.2 归属约定

- `tenant_id = 0`：平台侧 / 共享；`tenant_id = X` 指向 `merchants.id`。
- 现有 `merchant_id` / `mid` 是**上游业务商户号字符串**，与租户 id 无关，不要混用。
- 平台超管（`id = catch.super_admin` 且 `tenant_id = 0`）看全部；商户用户只看自己租户的数据，平台共享数据仅对 `Tenancy::SHARED_TABLES` 里的规范 / 字典表（`features`、`third_api_endpoints`、`exchange_rates`、`emojis`）开放。
- 创建记录时 `tenant_id` 由 `AppServiceProvider` 的 `eloquent.creating` 钩子按 `MerchantContext` 统一写入；商户用户强制继承自己的租户，平台超管可用请求参数 `tenant_id` 指定归属。编辑时归属受保护，只有平台超管能转移。
- **无登录态（队列 / CLI / 群发）不做租户过滤**，与改造前一致：群发任务按 `bot_id` / `chat_id` 精确定位，不做全表扫描。
- 存量数据一律回填 `tenant_id = 0`，不需要按 `creator_id` 推断归属。

### 12.3 创建商户与商户超管

```
php artisan merchant:create "商户A" a001 --username=a_admin --email=a@example.com --password=xxxx
```

商户超管的**角色**由平台超管在后台分配，命令不代分配。平台超管在后台顶栏「归属商户」下拉选中商户后，之后所有新建的配置表单都会自动带上 `tenant_id`；不选即建平台共享数据。

### 12.4 密钥与 `APP_KEY`

- 密钥对绑定在 `thirdapi_config` 一行（= 一个上游），在「三方 API 配置」编辑弹窗里生成 / 吊销。
- `secret_key` 用 `Crypt::encryptString()`（Laravel `APP_KEY`）加密入库，**明文只在生成响应里出现一次**，列表 / 详情只给掩码；`third_api_configs` 缓存已排除全部凭证字段。
- ⚠️ **`APP_KEY` 变更会让所有 `secret_key` 不可解**：上线前备份 `APP_KEY`；变更时必须通知全部三方重新生成密钥。`deploy.sh` 的 `verify_app_key()` 会把指纹记在 `storage/app/app_key.fingerprint`，不一致就停止部署；确需轮换时先备份旧值，再以 `ALLOW_APP_KEY_ROTATION=1 ./deploy.sh app` 重跑，并重新下发所有密钥。
- 旧的 `token` / `public_key` / `secrept_key` 是「我方 → 上游」方向的凭证，仍在用，**不要删**。

### 12.5 签名与挂载范围

- 中间件别名 `validate.upstream`，当前挂在 `POST /api/upstream/v1/ping`（接入自检）。后续需要加密处理的三方接口统一放 `api/upstream/v1` 分组。
- 请求头：`X-Access-Key`、`X-Timestamp`（Unix 秒，±300s）、`X-Nonce`（≤64，`[A-Za-z0-9_-]`）、`X-Signature`（HMAC-SHA256 小写 hex）。
- 待签串：`METHOD\nPATH?SORTED_QUERY\nTIMESTAMP\nNONCE\nBODY_SHA256`；`PATH` 用 `getPathInfo()`，query 按 key 升序且键值 `rawurlencode`，`BODY_SHA256` 是原始 body 的 sha256 小写 hex（GET 用空串的 sha256）。验签通过后才用 `Cache::add` 原子占用 nonce（600s）。
- **不挂**：`/api/webhook/pull`（Telegram secret_token）、`/api/hooks/{token}`（已有 per-hook `CallbackSigner`）、miniapp initData、`telegram/events/*`、`collect/*`、`tg/user/*`、`api/bot/send/group`、所有后台登录态接口。
- `ValidateApiKey`（`EXTERNAL_API_KEY`，前端 / 内部采集器）原样保留，与新体系并存。

### 12.6 上线检查补充

- [ ] `catch:migrate telegram` 已执行，`merchants` 表与全部业务表都有 `tenant_id`
- [ ] `APP_KEY` 已备份并纳入部署校验
- [ ] 新增后台路由 / 中间件后已重跑 `route:cache`（`php artisan route:list` 能看到 `third-config.keys.generate` 与 `api.upstream.ping`）
- [ ] 群发回归：`sendToGroup`、队列、回执、进度、日志与改造前一致

---

## 13. 一个机器人绑定多个上游

同一个上游公司可能有多个地址对应不同项目，因此机器人不再只有一个上游。

**三层关系**

| 层 | 表 | 含义 |
|---|---|---|
| 候选池 | `bot_third_configs` | 机器人 ↔ 多个上游，`is_default` 给没指定上游的调用兜底 |
| 能力声明 | `third_config_features` | 上游 ↔ 功能，只允许声明 `needs_upstream=1` 的功能 |
| 具体落点 | `features_binds.third_config_id` | 每个功能在「机器人绑定的上游 ∩ 声明支持该功能的上游」里指定一个 |

`bots.third_config_id` 保留为**默认上游镜像**，由 `UpstreamResolver::setDefault()/syncBotConfigs()` 同步维护；群发等原本只读这列的链路不用改。

**`needs_upstream` 由代码声明，不是人工勾选**：`BaseCustomFeature::upstreamEndpoints()`（实现 `EndpointProvider` 的功能自动从 `endpoints()` 派生，钱包功能手工声明）→ `telegram:sync-features` 写入 `features.needs_upstream` 与 `feature_upstream_endpoints`。群发 / 私聊 / 踢人 / @ / 快捷回复 / 表情采集 / 本地钱包余额与账单等 9 个功能标记为不需要上游，后台「上游绑定功能」的候选里根本不出现，后端保存时也会二次拒绝。

**配置顺序**：先在「三方配置」里给上游绑定功能 → 再到「上游接口配置」配这些功能对应的接口路径（页面只显示绑定功能用到的 code，后端 `save()` 同样按白名单校验）→ 在机器人列表「配置上游」里绑定多个上游并指定默认 → 最后在「功能配置」弹框里给需上游的功能指定承接上游。未绑定功能的上游接口列表为空，属正常提示。

**运行时**统一走 `UpstreamResolver::resolveId()`：绑定级上游 > 机器人候选池默认上游；指定的上游不属于该机器人时返回 0（不静默串到别人的上游）。`features.config.upstream_id` 跨机器人共享，已废弃。候选池为空的历史机器人不做校验，存量配置不会失效。

**上线检查补充**

- [ ] `catch:migrate telegram` 已执行，迁移里的回填把原 `bots.third_config_id` 写进了 `bot_third_configs`
- [ ] 部署后跑过 `php artisan telegram:sync-features`（否则 `needs_upstream` 与功能→接口映射不会更新）
- [ ] 新增 5 条路由后重跑 `route:cache`（`features/upstream-meta` 必须注册在 `features/{feature}` 之前）
- [ ] 机器人列表能看到多个上游 tag 与默认项标记；功能配置弹框里需上游功能显示上游名、官方 API 功能不出现上游下拉
