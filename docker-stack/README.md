# 公共 Docker 栈（Shared Docker Stack）

把 `php / node / mysql / redis / pgsql / nginx` 六类环境集中成一套**共享栈**，
部署在 Linux 宿主的 `/opt` 共用区，所有项目只跑各自的 app 容器并接入同一个网络。

```
/opt/docker-stack/      <- 本目录（共享栈定义，部署到这里）
/opt/projects/          <- 所有项目代码统一存放目录
```

## 设计要点

- **单一共享栈 + 项目接入**：共享栈长期运行 `mysql / redis / pgsql / nginx` 和
  一个常驻 `node` 环境；`php` / `node` 同时被打成基础镜像
  （`stack-php:8.4` / `stack-node:20`）。每个项目只定义自己的 app 容器
  （php-fpm、队列、reverb、telegram、前端），引用公共镜像并通过 `external`
  网络 `stack` 接入。
- **宿主路径全部在 `/opt` 共用区**：配置、数据卷、项目代码都在 `/opt` 下，
  多项目共用，互不干扰（MySQL/PG 用不同库，Redis 用不同 DB 号）。
- **共享 Nginx 按域名路由**：把项目 vhost 放到 `conf/nginx/sites/` 即可，
  静态资源与 `SCRIPT_FILENAME` 都从 `/opt/projects` 读取，路径与各容器一致。

## 一、部署共享栈

```bash
# 1. 建目录
sudo mkdir -p /opt/docker-stack /opt/projects

# 2. 把本目录内容放到 /opt/docker-stack（示例用 git 或 scp/cp）
sudo cp -r docker-stack/. /opt/docker-stack/

# 3. 配置环境变量
cd /opt/docker-stack
cp .env.example .env
# 按需修改 STACK_ROOT / PROJECTS_ROOT / 各服务端口与密码

# 4. 构建公共镜像（php / node）
docker compose build php node

# 5. 启动共享基础设施
docker compose up -d mysql redis pgsql nginx node

# 6. 校验
docker compose ps
```

> 其它项目要复用本栈，只需把它们的 app 容器接入 `stack` 网络，
> 并在 `conf/nginx/sites/` 放一份 vhost，无需再起一套 mysql/redis/nginx。

## 二、接入一个项目（以 telegram-bot 为例）

```bash
# 1. 把项目代码放到共享区（保留目录名 telegram-bot）
sudo mkdir -p /opt/projects/telegram-bot
sudo cp -r /path/to/telegram-bot/. /opt/projects/telegram-bot/

# 2. 配置项目 .env（与共享栈对齐）
#    DB_HOST=mysql   DB_PORT=3306   DB_DATABASE=bots   DB_USERNAME=bots   DB_PASSWORD=bots123456
#    REDIS_HOST=redis   REDIS_PORT=6379
#    APP_URL=http://tgbot.local
#    REVERB_HOST=tgbot.local
#    VITE_BASE_URL=http://tgbot.local/api/
#    VITE_REVERB_HOST=tgbot.local
#    # 项目代码位置（两处 PROJECTS_ROOT 必须一致！）：
#    PROJECTS_ROOT=/var/www            # 项目代码所在的根目录（共享栈的 nginx 也按它挂载）
#    PROJECT_DIR=easyTelegramManager   # 本项目在 PROJECTS_ROOT 下的目录名
#
# 说明：项目不必非得放在 /opt/projects 下。只要「共享栈 .env 的 PROJECTS_ROOT」
#      和「项目 .env 的 PROJECTS_ROOT」指向同一个父目录，且 PROJECT_DIR 等于项目
#      实际目录名，挂载与 nginx 的 SCRIPT_FILENAME 就能对上。

# 3. 启动本项目 app 容器（复用 stack-php / stack-node 镜像，接入 stack 网络）
cd /opt/projects/telegram-bot
docker compose up -d

# 4. 本机解析域名（开发机 /etc/hosts）
#    127.0.0.1 tgbot.local
# 浏览器打开 http://tgbot.local
```

## 三、新增一个项目

1. 代码放到 `/opt/projects/<新项目名>`。
2. 新项目 `docker-compose.yml` 复用 `stack-php:8.4` / `stack-node:20` 镜像，
   挂载 `${PROJECTS_ROOT}/<新项目名>:/opt/projects/<新项目名>`，
   `networks.stack` 设为 `external: true`。
3. 在 `conf/nginx/sites/` 增加 `<新项目名>.conf`，`server_name` 用新域名，
   `fastcgi_pass <新项目容器名>:9000`，`SCRIPT_FILENAME` 指向对应路径。
4. `docker compose -f /opt/docker-stack/docker-compose.yml exec nginx nginx -s reload`。

## 四、多项目资源隔离约定

| 服务    | 隔离方式                                            |
|---------|-----------------------------------------------------|
| MySQL   | 每个项目用独立 `database`（`stack` 用户可建库）      |
| PGSQL   | 每个项目用独立 `database`（`stack` 超级用户可建库）  |
| Redis   | 每个项目用不同 DB 号（0-15），或通过不同 key 前缀    |
| Nginx   | 不同 `server_name` 路由到不同项目容器                |

## 五、与原 per-project 配置的差异

- 原 `docker/`（php/nginx/mysql）为旧的单项目配置，已被本共享栈取代；
  保留未删，仅作参考。新项目请统一使用 `docker-stack/` 与项目根 `docker-compose.yml`。
- 因宿主为 Linux（非 Windows NTFS），`php.ini` 中原有的 opcache/realpath
  专用调优已移除，恢复为通用 Linux 配置。

## 六、为新项目创建独立数据库（更细粒度）

共享栈 `initdb` 里的 `stack` 超级用户适合手动建库；若想**每项目一套库 + 专用账号**
（互不越权），用仓库自带的脚本：

```bash
cd /opt/docker-stack
chmod +x scripts/*.sh

# MySQL：建 bot_<project> 库 + <project> 账号（密码随机生成并打印）
./scripts/create-project-db.sh <project>

# PostgreSQL：建 bot_<project> 库 + <project> 角色
./scripts/create-project-db-pg.sh <project>
```

脚本在共享栈的 mysql/pgsql 容器内执行，结果即生效，无需重启。
把打印出的 `DB_DATABASE / DB_USERNAME / DB_PASSWORD` 填进项目 `.env` 即可。

## 七、新增项目 vhost 模板

`conf/nginx/sites/_template.conf` 是一个**参数化模板**，复制为 `<project>.conf`
并替换占位符（`<DOMAIN_LIST>` / `<CONTAINER>` / `<PROJECT>` / `<PORT>`）即可，
与数据库类型无关（pgsql 项目只需在自身 `.env` 设 `DB_CONNECTION=pgsql`）。
`<DOMAIN_LIST>` 支持空格分隔的多个域名，例如 `demo.local www.demo.local api.demo.local`。

```bash
cp conf/nginx/sites/_template.conf conf/nginx/sites/<project>.conf
# 编辑替换占位符 ...
docker compose exec nginx nginx -s reload
```

完整的新项目接入流程见「二、接入一个项目」+ 本章六、七。

## 八、常见排错

- **`The "PROJECTS_ROOT" variable is not set`**：项目 `.env` 里缺少 `PROJECTS_ROOT`
  （`.env` 被 gitignore，部署时容易漏）。补上 `PROJECTS_ROOT` 与 `PROJECT_DIR` 即可。
- **`pull access denied for stack-php` / `No such image: stack-php:8.4`**：公共镜像还没构建。
  共享栈必须先 `docker compose build php node` 再 `up -d mysql redis pgsql nginx node`，
  项目 compose 用的是 `image: stack-php:8.4` 本地镜像，不会自己 build，也不会去拉 registry。
- **502 / `Primary script unknown`**：nginx 的 `SCRIPT_FILENAME` 路径与 php 容器挂载路径不一致。
  核对共享栈 `PROJECTS_ROOT` 与项目 `PROJECTS_ROOT`/`PROJECT_DIR` 是否一致，
  以及 vhost 里的 `/opt/projects/<PROJECT_DIR>/public` 是否正确。
- **php 容器启动即退出**：项目 compose 的 php 服务使用 `image:` 而非 `build:`，不会自己构建镜像；
  必须先在共享栈构建好 `stack-php:8.4`。
