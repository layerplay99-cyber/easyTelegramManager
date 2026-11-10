#!/usr/bin/env bash
# ============================================================================
# telegram-bot —— 一键安装脚本（自包含 Docker 部署）
# ----------------------------------------------------------------------------
# 目标机只需要 Docker（含 compose 插件）。本脚本负责：
#   下载发布包 → 校验 SHA256 → 解压 → 生成配置（.env / 前端构建变量 / Nginx）
#   → 构建前端 → 起容器 → 建表初始化 → 打印访问地址
#
# 与 deploy.sh 的分工：
#   deploy.sh   面向开发机，从 git 拉代码、复用 /opt/docker-stack 共享栈
#   install.sh  面向客户机，从下载站拉 zip，MySQL/Redis/Nginx 全部自包含
#
# 用法：
#   curl -fsSL https://example.com/site/install.sh -o /tmp/easytg.sh
#   sudo bash /tmp/easytg.sh --domain tg.example.com
#
#   sudo bash install.sh --domain tg.example.com --ssl      # 启用 HTTPS
#   sudo bash install.sh --domain tg.example.com --dry-run  # 只看会做什么
#   sudo bash install.sh --update                           # 升级到最新版
#   sudo bash install.sh --domain x.com --package ./a.zip   # 离线安装
#
# 幂等：全新安装只在空目录进行；--update 覆盖代码但保留 .env 与数据卷。
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------------------------------------------------------- 默认配置
DOMAIN="${TG_DOMAIN:-}"
INSTALL_DIR="${TG_DIR:-/opt/easytg}"
VERSION="${TG_VERSION:-latest}"
# 下载站地址：__SITE_URL__ 由 package-release.sh 在发布时替换成真实站点；
# 没替换（源码里直接跑）就走下面的推断逻辑
BASE_URL="${TG_BASE_URL:-__SITE_URL__}"
HTTP_PORT="${TG_HTTP_PORT:-80}"
HTTPS_PORT="${TG_HTTPS_PORT:-443}"
PACKAGE="${TG_PACKAGE:-}"
ENABLE_SSL=0
INSTALL_DOCKER=0
DRY_RUN=0
UPDATE=0
SKIP_FRONTEND=0
WITH_TELETHON=0

COMPOSE_FILE="docker-compose.standalone.yml"

PKG_URL=""
PKG_FILE=""

# ---------------------------------------------------------------- 输出
c_green='\033[32m'; c_yellow='\033[33m'; c_red='\033[31m'; c_dim='\033[2m'; c_off='\033[0m'

step() { echo -e "\n${c_green}==>${c_off} $*"; }
info() { echo -e "    $*"; }
dim()  { echo -e "    ${c_dim}$*${c_off}"; }
warn() { echo -e "${c_yellow}[warn]${c_off} $*"; }
die()  { echo -e "${c_red}[error]${c_off} $*" >&2; exit 1; }

usage() {
    cat <<'TXT'
telegram-bot 一键安装（自包含 Docker：MySQL / Redis / PHP / Nginx 全在这一套里）

用法：
  curl -fsSL <站点>/site/install.sh -o /tmp/easytg.sh
  sudo bash /tmp/easytg.sh --domain tg.example.com

参数：
  --domain <域名>      必填（--update 时可省），后台访问域名
  --dir <目录>         安装目录，默认 /opt/easytg
  --version <版本>     安装版本，默认 latest（读下载站的 latest.txt）
  --port <端口>        HTTP 端口，默认 80
  --ssl                启用 HTTPS（证书放 /etc/nginx/ssl/<域名>/）
  --package <zip>      离线安装：使用本地发布包，不下载
  --base-url <url>     官网根地址（含 /site，如 https://x.com/site），默认按主域推断
  --install-docker     缺 Docker 时自动安装（Debian / Ubuntu）
  --skip-frontend      跳过前端构建（要求发布包已带 web/dist 且域名一致）
  --with-telethon      一并启动真人号（Telethon）服务
  --update             升级现有安装（保留配置与数据）
  --dry-run            只打印步骤，不改动系统
  -h, --help           显示本帮助
TXT
}

while [ $# -gt 0 ]; do
    case "$1" in
        --domain) DOMAIN="${2:-}"; shift 2 ;;
        --dir) INSTALL_DIR="${2:-}"; shift 2 ;;
        --version) VERSION="${2:-}"; shift 2 ;;
        --port) HTTP_PORT="${2:-}"; shift 2 ;;
        --ssl) ENABLE_SSL=1; shift ;;
        --package) PACKAGE="${2:-}"; shift 2 ;;
        --base-url) BASE_URL="${2:-}"; shift 2 ;;
        --install-docker) INSTALL_DOCKER=1; shift ;;
        --skip-frontend) SKIP_FRONTEND=1; shift ;;
        --with-telethon) WITH_TELETHON=1; shift ;;
        --update) UPDATE=1; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "未知参数：$1（--help 看用法）" ;;
    esac
done

[ "$(id -u)" = "0" ] || [ "$DRY_RUN" = "1" ] || die "请用 root 执行：sudo bash $0 --domain <域名>"

if [ "$UPDATE" = "0" ] && [ -z "$DOMAIN" ]; then
    die "缺少 --domain（例：--domain tg.example.com）；--help 看完整参数"
fi

# 未注入（源码里直接跑）时占位符要当成空，否则会去请求 https://__SITE_URL__/
if [ "$BASE_URL" = "__SITE_URL__" ]; then
    BASE_URL=""
fi

# 安装器部署在官网路径下：<origin>/site/install.sh，BASE_URL 即官网根（含 /site），
# 拉包地址 = $BASE_URL/downloads/...
if [ -z "$BASE_URL" ]; then
    if [ -f "$SCRIPT_DIR/site/install.sh" ] || [ -d "$SCRIPT_DIR/site/downloads" ]; then
        BASE_URL=""   # 本机源码目录直接跑，走 --package 或提示
    else
        BASE_URL="https://${DOMAIN}/site"
    fi
fi
BASE_URL="${BASE_URL%/}"

rand() { head -c 24 /dev/urandom | base64 | tr -d '/+=' | head -c 32; }
gen_key() { printf 'base64:%s' "$(head -c 32 /dev/urandom | base64 | tr -d '\n')"; }

# ---------------------------------------------------------------- 环境检查
detect_compose() {
    if docker compose version >/dev/null 2>&1; then
        COMPOSE="docker compose"
    elif command -v docker-compose >/dev/null 2>&1; then
        COMPOSE="docker-compose"
    else
        return 1
    fi
}

install_docker() {
    step "安装 Docker（官方脚本）"
    if [ "$DRY_RUN" = "1" ]; then
        info "curl -fsSL https://get.docker.com | sh"
        return 0
    fi
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker >/dev/null 2>&1 || true
}

check_env() {
    step "检查运行环境"

    if [ "$DRY_RUN" = "1" ]; then
        info "dry-run：跳过 Docker / 端口 / 磁盘检查"
        return 0
    fi

    command -v curl >/dev/null 2>&1 || die "缺少 curl，请先安装：apt-get install -y curl"
    command -v unzip >/dev/null 2>&1 || die "缺少 unzip，请先安装：apt-get install -y unzip"

    if ! command -v docker >/dev/null 2>&1; then
        if [ "$INSTALL_DOCKER" = "1" ]; then
            install_docker
        else
            die "未检测到 Docker。可加 --install-docker 自动安装，或先手动装：curl -fsSL https://get.docker.com | sh"
        fi
    fi

    detect_compose || die "未检测到 compose（docker compose / docker-compose）。请升级 Docker 或安装 compose 插件"
    info "docker: $(docker --version | cut -d, -f1)"
    info "compose: $COMPOSE"
    info "安装目录: $INSTALL_DIR"

    if ! docker info >/dev/null 2>&1; then
        die "Docker 服务未运行：systemctl start docker"
    fi
}

# ---------------------------------------------------------------- 发布包
resolve_url() {
    local version="$1"

    if [ -n "$PACKAGE" ]; then
        return 0
    fi

    # dry-run 不联网：只拼出将要访问的地址（由调用方打印）
    if [ "$DRY_RUN" = "1" ]; then
        PKG_URL="${BASE_URL:-<下载站>}/downloads/telegram-bot-${VERSION}.zip"
        return 0
    fi

    if [ -z "$BASE_URL" ]; then
        die "无法推断下载站地址：请加 --base-url https://你的站点，或用 --package 指定本地 zip"
    fi

    if [ "$version" = "latest" ]; then
        local v
        v="$(curl -fsSL --max-time 20 "$BASE_URL/downloads/latest.txt" 2>/dev/null | tr -d '[:space:]' || true)"
        [ -n "$v" ] || die "取版本号失败：$BASE_URL/downloads/latest.txt（下载站还没发布过安装包？）"
        VERSION="$v"
        info "最新版本：$VERSION"
    fi

    PKG_URL="$BASE_URL/downloads/telegram-bot-$VERSION.zip"
}

download_package() {
    step "获取发布包"

    if [ -n "$PACKAGE" ]; then
        if [ "$DRY_RUN" = "1" ]; then
            info "使用本地包：$PACKAGE（dry-run 不检查是否存在）"
            return 0
        fi

        [ -f "$PACKAGE" ] || die "本地包不存在：$PACKAGE"

        PKG_FILE="$PACKAGE"
        info "使用本地包：$PKG_FILE"
        return 0
    fi

    resolve_url "$VERSION"
    PKG_FILE="/tmp/telegram-bot-$VERSION.zip"

    info "下载：$PKG_URL"
    if [ "$DRY_RUN" = "1" ]; then
        return 0
    fi

    curl -fL --retry 3 --connect-timeout 20 -o "$PKG_FILE" "$PKG_URL" \
        || die "下载失败：$PKG_URL"

    # 校验值可选：站点发布了 .sha256 就核对，没有就跳过（不阻断安装）
    if curl -fsSL --max-time 20 -o "${PKG_FILE}.sha256" "${PKG_URL}.sha256" 2>/dev/null; then
        ( cd "$(dirname "$PKG_FILE")" && sha256sum -c "$(basename "${PKG_FILE}.sha256")" >/dev/null 2>&1 ) \
            || die "SHA256 校验失败，包可能损坏：$PKG_FILE"
        info "SHA256 校验通过"
    else
        dim "站点未提供 .sha256，跳过校验"
    fi
}

# ---------------------------------------------------------------- 解压
prepare_dir() {
    step "准备安装目录 $INSTALL_DIR"

    if [ "$DRY_RUN" = "1" ]; then
        info "mkdir -p $INSTALL_DIR"
        return 0
    fi

    mkdir -p "$INSTALL_DIR"

    if [ "$UPDATE" = "0" ] && [ -n "$(ls -A "$INSTALL_DIR" 2>/dev/null)" ]; then
        die "目录非空：$INSTALL_DIR。升级请用 --update，换目录请用 --dir"
    fi
}

unpack() {
    step "解压发布包"

    if [ "$DRY_RUN" = "1" ]; then
        info "unzip -q -o ${PKG_FILE:-<发布包>} -d $INSTALL_DIR"
        return 0
    fi

    # 建立包时以项目根为顶层，直接解压即可
    if [ "$UPDATE" = "1" ]; then
        # 升级：覆盖代码，但绝不覆盖用户配置
        unzip -q -o "$PKG_FILE" -d "$INSTALL_DIR" -x ".env" "web/.env.production" "docker/nginx-standalone.conf"
    else
        unzip -q -o "$PKG_FILE" -d "$INSTALL_DIR"
    fi
}

# ---------------------------------------------------------------- 配置
# 就地改 .env 的某个键（存在则整行替换，不存在则追加）
set_env() {
    local file="$1" key="$2" value="$3"

    if grep -qE "^${key}=" "$file" 2>/dev/null; then
        # 值里可能含 / & 等字符，用 | 做分隔并转义
        local esc
        esc="$(printf '%s' "$value" | sed -e 's/[&|\\]/\\&/g')"
        sed -i "s|^${key}=.*|${key}=${esc}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

write_env() {
    step "生成服务端配置 .env"

    ENV_FILE="$INSTALL_DIR/.env"

    if [ "$DRY_RUN" = "1" ]; then
        info "cp $INSTALL_DIR/.env.example $ENV_FILE （已存在则保留）"
        return 0
    fi

    if [ -s "$ENV_FILE" ]; then
        info "已存在 .env，保留现有配置（仅补缺失项）"
    else
        cp "$INSTALL_DIR/.env.example" "$ENV_FILE"
    fi

    local app_url scheme="http" reverb_port="$HTTP_PORT" tls="false" wss_scheme="ws"
    if [ "$ENABLE_SSL" = "1" ]; then
        scheme="https"; reverb_port="$HTTPS_PORT"; tls="true"; wss_scheme="wss"
    fi
    app_url="$scheme://$DOMAIN"

    local db_pass root_pass ext_key reverb_id reverb_key reverb_secret hook_secret telethon_token
    db_pass="${DB_PASSWORD:-$(rand)}"
    root_pass="${DB_ROOT_PASSWORD:-$(rand)}"
    ext_key="${EXTERNAL_API_KEY:-$(rand)}"
    reverb_id="${REVERB_APP_ID:-$((RANDOM % 900000 + 100000))}"
    reverb_key="${REVERB_APP_KEY:-$(rand)}"
    reverb_secret="${REVERB_APP_SECRET:-$(rand)}"
    hook_secret="${TELEGRAM_HOOK_SECRET:-$(rand)}"
    telethon_token="${TELETHON_CALLBACK_TOKEN:-$(rand)}"

    set_env "$ENV_FILE" APP_NAME "EasyTG"
    set_env "$ENV_FILE" APP_ENV "production"
    set_env "$ENV_FILE" APP_DEBUG "false"
    set_env "$ENV_FILE" APP_URL "$app_url"

    # APP_KEY 只在为空时生成，重装/升级不能换（会解不开已加密的字段）
    if ! grep -qE '^APP_KEY=base64:.+' "$ENV_FILE" 2>/dev/null; then
        set_env "$ENV_FILE" APP_KEY "$(gen_key)"
    fi

    set_env "$ENV_FILE" DB_CONNECTION "mysql"
    set_env "$ENV_FILE" DB_HOST "mysql"
    set_env "$ENV_FILE" DB_PORT "3306"
    set_env "$ENV_FILE" DB_DATABASE "${DB_DATABASE:-easytg}"
    set_env "$ENV_FILE" DB_USERNAME "${DB_USERNAME:-easytg}"
    set_env "$ENV_FILE" DB_PASSWORD "$db_pass"
    set_env "$ENV_FILE" DB_PREFIX "${DB_PREFIX:-bm}"
    set_env "$ENV_FILE" DB_ROOT_PASSWORD "$root_pass"

    set_env "$ENV_FILE" REDIS_HOST "redis"
    set_env "$ENV_FILE" REDIS_PORT "6379"
    set_env "$ENV_FILE" REDIS_PASSWORD "null"
    set_env "$ENV_FILE" REDIS_CLIENT "phpredis"
    set_env "$ENV_FILE" QUEUE_CONNECTION "redis"

    set_env "$ENV_FILE" REVERB_APP_ID "$reverb_id"
    set_env "$ENV_FILE" REVERB_APP_KEY "$reverb_key"
    set_env "$ENV_FILE" REVERB_APP_SECRET "$reverb_secret"
    set_env "$ENV_FILE" REVERB_HOST "$DOMAIN"
    set_env "$ENV_FILE" REVERB_PORT "8080"
    set_env "$ENV_FILE" REVERB_SCHEME "http"

    set_env "$ENV_FILE" EXTERNAL_API_KEY "$ext_key"
    set_env "$ENV_FILE" TELEGRAM_HOOK_SECRET "$hook_secret"
    set_env "$ENV_FILE" TELETHON_BASE_URL "http://telegram-py:8081"
    set_env "$ENV_FILE" TELETHON_MEDIA_PATH "/data/media"
    set_env "$ENV_FILE" TELETHON_CALLBACK_TOKEN "$telethon_token"

    # 记下下载站地址，--update 时不必再传 --base-url
    if [ -n "$BASE_URL" ]; then
        set_env "$ENV_FILE" TG_BASE_URL "$BASE_URL"
    fi

    # 供 docker-compose.standalone.yml 读取
    set_env "$ENV_FILE" DOMAIN "$DOMAIN"
    set_env "$ENV_FILE" HTTP_PORT "$HTTP_PORT"
    set_env "$ENV_FILE" HTTPS_PORT "$HTTPS_PORT"

    WEB_ENV="$INSTALL_DIR/web/.env.production"
}

write_web_env() {
    step "生成前端构建变量 web/.env.production"

    if [ "$DRY_RUN" = "1" ]; then
        info "写入 VITE_BASE_URL / VITE_REVERB_* / VITE_API_KEY"
        return 0
    fi

    local scheme="http" ws_scheme="ws" port="$HTTP_PORT" tls="false"
    if [ "$ENABLE_SSL" = "1" ]; then
        scheme="https"; ws_scheme="wss"; port="$HTTPS_PORT"; tls="true"
    fi

    local ext_key reverb_key
    ext_key="$(grep -E '^EXTERNAL_API_KEY=' "$ENV_FILE" | tail -1 | cut -d= -f2-)"
    reverb_key="$(grep -E '^REVERB_APP_KEY=' "$ENV_FILE" | tail -1 | cut -d= -f2-)"

    cat > "$WEB_ENV" <<EOF
# 由 install.sh 生成；改完需重新构建前端（install.sh --update 会自动重建）
VITE_BASE_URL=${scheme}://${DOMAIN}/api
VITE_APP_NAME=EasyTG
VITE_REVERB_APP_KEY=${reverb_key}
VITE_REVERB_HOST=${DOMAIN}
VITE_REVERB_PORT=${port}
VITE_REVERB_SCHEME=${scheme}
VITE_REVERB_USE_TLS=${tls}
VITE_WSS_URL=${ws_scheme}://${DOMAIN}/app
VITE_API_KEY=${ext_key}
EOF

    info "VITE_BASE_URL=${scheme}://${DOMAIN}/api"
}

render_nginx() {
    step "生成 Nginx 站点配置"

    local tpl="$INSTALL_DIR/docker-stack/standalone/nginx.conf.template"
    local out="$INSTALL_DIR/docker/nginx-standalone.conf"

    if [ "$DRY_RUN" = "1" ]; then
        info "渲染 $out（server_name $DOMAIN，官网挂 /site/）"
        return 0
    fi

    [ -f "$tpl" ] || die "缺少模板：$tpl（发布包不完整？）"

    mkdir -p "$(dirname "$out")"

    local ssl_block=""
    if [ "$ENABLE_SSL" = "1" ]; then
        ssl_block="    listen 443 ssl;
    ssl_certificate     /etc/nginx/ssl/${DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/nginx/ssl/${DOMAIN}/privkey.pem;"
    fi

    local ssl_tmp
    ssl_tmp="$(mktemp)"
    printf '%s\n' "$ssl_block" > "$ssl_tmp"

    # __SSL_LISTEN__ 是多行块：用 awk 把占位行替换成临时文件内容
    awk -v sslfile="$ssl_tmp" '
        /__SSL_LISTEN__/ { while ((getline line < sslfile) > 0) print line; next }
        { print }
    ' "$tpl" \
        | sed -e "s|__DOMAIN__|${DOMAIN}|g" \
        > "$out"

    rm -f "$ssl_tmp"

    if [ "$ENABLE_SSL" = "1" ]; then
        [ -f "/etc/nginx/ssl/${DOMAIN}/fullchain.pem" ] \
            || warn "证书不存在：/etc/nginx/ssl/${DOMAIN}/fullchain.pem（放好证书后 install.sh --update 重新加载）"
    fi

    info "写入 $out"
}

# ---------------------------------------------------------------- 前端构建
build_frontend() {
    step "构建前端"

    if [ "$SKIP_FRONTEND" = "1" ]; then
        [ -d "$INSTALL_DIR/web/dist" ] || die "--skip-frontend 但 web/dist 不存在，前端会 404"
        warn "已跳过构建（使用发布包内的 web/dist，需与当前域名一致）"
        return 0
    fi

    if [ "$DRY_RUN" = "1" ]; then
        info "docker run --rm -v \$DIR/web:/app -w /app node:20-alpine sh -c 'corepack enable && yarn install && yarn build'"
        return 0
    fi

    # 用一次性 node 容器构建，目标机不需要装 Node
    if docker run --rm -v "$INSTALL_DIR/web:/app" -w /app -e CI=true node:20-alpine \
        sh -c 'corepack enable >/dev/null 2>&1 || true; yarn install --non-interactive && yarn build'; then
        info "前端产物：$(ls -1 "$INSTALL_DIR/web/dist" 2>/dev/null | wc -l) 个文件"
        return 0
    fi

    if [ -d "$INSTALL_DIR/web/dist" ]; then
        warn "前端构建失败，回退使用发布包内的 web/dist（域名不一致会导致白屏，请检查）"
        return 0
    fi

    die "前端构建失败，且发布包内没有 web/dist。请检查网络（npm 源）或改用带 dist 的发布包 + --skip-frontend"
}

# ---------------------------------------------------------------- 容器
compose() {
    ( cd "$INSTALL_DIR" && $COMPOSE -f "$COMPOSE_FILE" "$@" )
}

start_services() {
    step "启动容器（首次会构建 PHP 镜像，需要几分钟）"

    if [ "$DRY_RUN" = "1" ]; then
        info "docker compose -f $COMPOSE_FILE up -d mysql redis php"
        info "docker compose -f $COMPOSE_FILE up -d nginx queue queue-ocr reverb"
        return 0
    fi

    # 先起依赖，再起应用：避免 vendor/缓存未就绪时 worker 崩溃刷日志
    compose up -d --build mysql redis php

    info "等待 MySQL 就绪…"
    local i
    for i in $(seq 1 60); do
        if compose exec -T mysql sh -c 'mysqladmin ping -h 127.0.0.1 -p"$MYSQL_ROOT_PASSWORD" --silent' >/dev/null 2>&1; then
            info "MySQL 就绪"
            break
        fi
        sleep 2

        if [ "$i" = "60" ]; then
            die "MySQL 60 秒内未就绪：docker compose logs mysql"
        fi
    done

    local profiles=""
    if [ "$WITH_TELETHON" = "1" ]; then
        profiles="--profile telethon"
    fi

    # shellcheck disable=SC2086
    compose $profiles up -d nginx queue queue-ocr reverb

    if [ "$WITH_TELETHON" = "1" ]; then
        compose --profile telethon up -d telegram-py
    fi
}

php_exec() {
    compose exec -T php sh -c "$1"
}

init_app() {
    step "初始化数据库与应用"

    if [ "$DRY_RUN" = "1" ]; then
        info "php artisan catch:migrate user --force && php artisan app:install"
        info "php artisan storage:link && php artisan telegram:sync-features"
        return 0
    fi

    # vendor 随发布包提供；万一缺失则容器内装（镜像已带 composer）
    if [ ! -f "$INSTALL_DIR/vendor/autoload.php" ]; then
        info "安装 PHP 依赖"
        php_exec "COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader --no-interaction"
    fi

    php_exec "php artisan catch:migrate user --force"
    php_exec "php artisan app:install"
    php_exec "php artisan storage:link || true"
    php_exec "php artisan telegram:sync-features || true"

    info "刷新缓存"
    php_exec "php artisan optimize:clear >/dev/null && php artisan config:cache && php artisan route:cache && php artisan view:cache" || warn "缓存预热失败（不影响使用）"

    # root 跑 artisan 会留下 root 属主的缓存文件，php-fpm 是 www-data
    php_exec "chown -R www-data:www-data storage bootstrap/cache" || true
}

restart_workers() {
    step "重启常驻服务"

    if [ "$DRY_RUN" = "1" ]; then
        info "docker compose restart queue queue-ocr reverb nginx"
        return 0
    fi

    compose restart queue queue-ocr reverb php nginx
}

summary() {
    local scheme="http"
    if [ "$ENABLE_SSL" = "1" ]; then
        scheme="https"
    fi

    # heredoc 不解释 \033 转义，颜色要先展开成真实字节
    local yellow off green
    yellow="$(printf '\033[33m')"
    green="$(printf '\033[32m')"
    off="$(printf '\033[0m')"

    cat <<EOF

${green}✔ 安装完成${off}

  后台地址   ${scheme}://${DOMAIN}
  官网/下载  ${scheme}://${DOMAIN}/site/
  安装目录   ${INSTALL_DIR}
  初始账号   catchadmin / catchadmin   ${yellow}（登录后请立刻改密码）${off}

常用命令：
  cd ${INSTALL_DIR}
  docker compose -f ${COMPOSE_FILE} ps                 # 容器状态
  docker compose -f ${COMPOSE_FILE} logs -f php        # PHP 日志
  sudo bash install.sh --update                        # 升级到最新版
  docker compose -f ${COMPOSE_FILE} down               # 停止（数据卷保留）

说明：
  · 域名需提前解析到本机；启用 HTTPS 请把证书放到 /etc/nginx/ssl/${DOMAIN}/ 后重跑 --update
  · 首次登录请到「系统 → 用户管理」修改 catchadmin 密码
EOF
}

# ---------------------------------------------------------------- 主流程
main() {
    echo -e "${c_green}telegram-bot 安装器${c_off}  ${c_dim}(standalone / docker)${c_off}"

    if [ "$DRY_RUN" = "1" ]; then
        warn "dry-run：只打印步骤，不改动系统"
    fi

    check_env

    if [ "$UPDATE" = "1" ]; then
        [ -f "$INSTALL_DIR/.env" ] || die "--update 但 $INSTALL_DIR/.env 不存在，不像是已安装的目录"

        DOMAIN="$(grep -E '^DOMAIN=' "$INSTALL_DIR/.env" | tail -1 | cut -d= -f2-)"

        [ -n "$DOMAIN" ] || die "--update 但 .env 里没有 DOMAIN，请手动指定 --domain"

        # 升级时沿用安装时记录的下载站，客户机不必再传 --base-url
        if [ -z "$BASE_URL" ]; then
            BASE_URL="$(grep -E '^TG_BASE_URL=' "$INSTALL_DIR/.env" | tail -1 | cut -d= -f2-)"

            if [ -n "$BASE_URL" ]; then
                info "沿用下载站：$BASE_URL"
            fi
        fi

        step "升级模式：$INSTALL_DIR（域名 $DOMAIN）"
    fi

    download_package
    prepare_dir
    unpack
    write_env
    write_web_env
    render_nginx
    build_frontend
    start_services
    init_app
    restart_workers

    if [ "$DRY_RUN" = "0" ]; then
        summary
    fi
}

main "$@"
