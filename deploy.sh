#!/usr/bin/env bash
# ============================================================================
# telegram-bot —— Docker 一键部署脚本
# ----------------------------------------------------------------------------
# 结构：共享栈（mysql/pgsql/nginx + stack-php/stack-node 镜像）在 /opt/docker-stack，
#       注意：共享栈没有 redis，各项目自带（本项目是 tgbot-redis）。
#       本项目只跑自己的 app 容器（php/queue/reverb/frontend/telethon），接入 stack 网络。
#
# 用法：
#   cp deploy.env.example deploy.env      # 只改你需要的（主要是 DOMAIN）
#   chmod +x deploy.sh
#
#   ./deploy.sh all        # 首次全量部署（共享栈 + 项目 + 构建前端）
#   ./deploy.sh update     # 后续更新（拉代码 → 迁移 → 清缓存 → 重启 → 前端构建）
#   ./deploy.sh stack      # 只装/更新共享栈
#   ./deploy.sh app        # 只部署项目（栈已就绪时用）
#   ./deploy.sh build      # 只重新构建前端
#   ./deploy.sh status     # 看状态
#
# 幂等：每一步都可重复执行，不会重复建库/重复生成配置（已存在则跳过）。
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------- 外部配置（存在则覆盖下面的默认值）----------------
if [ -f "$SCRIPT_DIR/deploy.env" ]; then
    set -a
    # shellcheck disable=SC1091
    . "$SCRIPT_DIR/deploy.env"
    set +a
fi

# ============================================================================
# 配置区 —— 真正要改的就这几个
# ============================================================================

# ★ 域名（Nginx server_name / APP_URL / 前端 VITE_* / Telethon 回调用）
DOMAIN="${DOMAIN:-tg.dg7788.top}"

# http / https。选 https 时把 ENABLE_SSL 设为 1 并填证书路径
PROTO="${PROTO:-https}"
ENABLE_SSL="${ENABLE_SSL:-0}"
SSL_CERT="${SSL_CERT:-/etc/nginx/ssl/${DOMAIN}/fullchain.pem}"
SSL_KEY="${SSL_KEY:-/etc/nginx/ssl/${DOMAIN}/privkey.pem}"
# 没有证书也能先跑起来：ENABLE_SSL=0 时只监听 80

# 项目代码位置（默认取脚本所在目录，一般不用改）
PROJECT_HOST_DIR="${PROJECT_HOST_DIR:-$SCRIPT_DIR}"
PROJECT_DIR="${PROJECT_DIR:-$(basename "$PROJECT_HOST_DIR")}"
PROJECTS_ROOT="${PROJECTS_ROOT:-$(dirname "$PROJECT_HOST_DIR")}"

# 共享栈位置与来源
STACK_ROOT="${STACK_ROOT:-/opt/docker-stack}"
STACK_SRC="${STACK_SRC:-$SCRIPT_DIR/docker-stack}"

# 代码仓库（首次部署目录不存在时才 clone）
GIT_REPO="${GIT_REPO:-https://github.com/layerplay99-cyber/easyTelegramManager.git}"
GIT_BRANCH="${GIT_BRANCH:-main}"

# 数据库（共享栈 mysql 内）
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-root}"
DB_DATABASE="${DB_DATABASE:-bots}"
DB_USERNAME="${DB_USERNAME:-bots}"
DB_PASSWORD="${DB_PASSWORD:-bots123456}"
DB_PREFIX="${DB_PREFIX:-bm}"

# 前端托管方式：dist = Nginx 直接托管 web/dist（生产推荐）；dev = 反代 vite dev server
SERVE_MODE="${SERVE_MODE:-dist}"

# 密钥（留空则自动生成）
EXTERNAL_API_KEY="${EXTERNAL_API_KEY:-}"
TELETHON_CALLBACK_TOKEN="${TELETHON_CALLBACK_TOKEN:-}"
REVERB_APP_ID="${REVERB_APP_ID:-160452}"
REVERB_APP_KEY="${REVERB_APP_KEY:-}"
REVERB_APP_SECRET="${REVERB_APP_SECRET:-}"

# 本地 Telegram Bot API Server（TDLib）身份：my.telegram.org 申请
# 留空时 docker-compose.yml 的 telegram-bot-api 服务会启动即退出（不影响其它容器）
TELEGRAM_API_ID="${TELEGRAM_API_ID:-}"
TELEGRAM_API_HASH="${TELEGRAM_API_HASH:-}"

# 是否跳过共享栈镜像构建（镜像已存在时可设 1 提速）
SKIP_IMAGE_BUILD="${SKIP_IMAGE_BUILD:-0}"

# ============================================================================
# 以下为派生变量，不用改
# ============================================================================

APP_URL="${PROTO}://${DOMAIN}"
CONTAINER_DIR="/opt/projects/${PROJECT_DIR}"
STACK_COMPOSE_FILE="$STACK_ROOT/docker-compose.yml"

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    COMPOSE="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE="docker-compose"
else
    echo "❌ 未找到 docker / docker compose"; exit 1
fi

stack_compose()  { $COMPOSE -f "$STACK_COMPOSE_FILE" "$@"; }
app_compose()    { $COMPOSE --project-directory "$PROJECT_HOST_DIR" "$@"; }
php_exec()       { docker exec tgbot-php sh -c "$1"; }   # 多命令必须 sh -c 包起来

log()  { echo -e "\033[32m[deploy]\033[0m $*"; }
warn() { echo -e "\033[33m[warn]\033[0m $*"; }
err()  { echo -e "\033[31m[error]\033[0m $*" >&2; }

rand() { head -c 24 /dev/urandom | base64 | tr -d '/+=' | head -c 32; }

# 确保某个 key 在 .env 里存在且为指定值（不存在就追加）
ensure_env_var() {
    local file="$1" key="$2" value="$3"
    touch "$file"
    if grep -q "^${key}=" "$file" 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$file"
    else
        printf '\n%s=%s\n' "$key" "$value" >> "$file"
    fi
}

# 读 .env 里某个 key 的当前值（不存在返回空）
env_val() {
    grep -m1 "^$2=" "$1" 2>/dev/null | cut -d= -f2- || true
}

# 确保某个 key 在 .env 里存在：已存在则保留原值（保护手改），只在缺失时补默认值
ensure_env_default() {
    local file="$1" key="$2" value="$3"
    touch "$file"
    if grep -q "^${key}=" "$file" 2>/dev/null; then
        return 0
    fi
    printf '%s=%s\n' "$key" "$value" >> "$file"
}

# ============================================================================
# 1. 共享栈
# ============================================================================
# 项目已自带 redis（主 docker-compose.yml 的 redis 服务 → tgbot-redis）。
# 若共享栈还残留旧的 stack-redis，会占用 stack 网络上的 redis 别名，
# 导致 tgbot-redis 起不来（别名解析冲突），这里显式移除，
# 保证一条脚本部署、无需再手动敲 docker rm。
remove_shared_redis() {
    if docker inspect stack-redis >/dev/null 2>&1; then
        log "移除冲突的共享栈 redis(stack-redis)，redis 改由项目自带 tgbot-redis 提供"
        docker rm -f stack-redis >/dev/null 2>&1 || true
    fi
}

# 清理历史遗留的「开发用 vhost」：sites/telegram-bot.conf（server_name tgbot.local、
# 前端反代 5173 dev server）。它在仓库里已改名 _dev.conf.example，但 cp -a 不会删除
# 目标目录里已有的文件，生产机上那份还会被 nginx 的 sites/*.conf 加载。
# 只删「确实还是开发配置」的那份：若它已被本项目 vhost 覆盖（server_name 是真实域名）则不动。
remove_legacy_dev_vhost() {
    local legacy="$STACK_ROOT/conf/nginx/sites/telegram-bot.conf"
    if [ -f "$legacy" ] && grep -q 'server_name[[:space:]]\+tgbot\.local' "$legacy"; then
        log "清理遗留的开发 vhost：conf/nginx/sites/telegram-bot.conf（server_name tgbot.local）"
        rm -f "$legacy"
        reload_nginx
    fi
}

deploy_stack() {
    log "部署共享栈 → $STACK_ROOT"

    mkdir -p "$STACK_ROOT"
    cp -a "$STACK_SRC/." "$STACK_ROOT/"

    if [ ! -f "$STACK_ROOT/.env" ]; then
        log "生成共享栈 .env"
        cat > "$STACK_ROOT/.env" <<EOF
STACK_ROOT=$STACK_ROOT
PROJECTS_ROOT=$PROJECTS_ROOT

TZ=Asia/Shanghai

MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD
MYSQL_DATABASE=$DB_DATABASE
MYSQL_USER=$DB_USERNAME
MYSQL_PASSWORD=$DB_PASSWORD
MYSQL_PORT=3306

PGSQL_USER=stack
PGSQL_PASSWORD=stack123456
PGSQL_DATABASE=stack
PGSQL_PORT=5432

NGINX_HTTP_PORT=80
NGINX_HTTPS_PORT=443
EOF
    else
        log "共享栈 .env 已存在，跳过"
    fi

    # 这两个路径决定 nginx 挂哪个目录（${PROJECTS_ROOT}:/opt/projects:ro），写错的话
    # nginx 容器里的 /opt/projects 会是空的（宿主机没这个目录时 Docker 还会自动建一个空的），
    # 静态文件全读不到 → try_files rewrite cycle → 全站 500。所以每次都强制对齐实际路径。
    # 注意：这里要填「宿主」路径（/opt），不是容器内的 /opt/projects。
    ensure_env_var "$STACK_ROOT/.env" STACK_ROOT    "$STACK_ROOT"
    ensure_env_var "$STACK_ROOT/.env" PROJECTS_ROOT "$PROJECTS_ROOT"

    if [ "$SKIP_IMAGE_BUILD" != "1" ]; then
        log "构建公共镜像 telegram-bot-php / telegram-bot-node（首次较慢）"
        stack_compose build php node
    fi

    log "启动 mysql / nginx（redis 由项目自带 tgbot-redis 提供）"
    stack_compose up -d mysql nginx

    remove_shared_redis
    remove_legacy_dev_vhost

    wait_mysql
    create_db

    log "✅ 共享栈就绪"
}

# 取正在运行的 stack-mysql 容器的实际 root 密码
# （避免 .env 与已初始化数据不一致导致 ERROR 1045 / ERROR 1524）
mysql_root_pw() {
    docker exec stack-mysql sh -c 'echo "$MYSQL_ROOT_PASSWORD"' 2>/dev/null || echo "$MYSQL_ROOT_PASSWORD"
}

wait_mysql() {
    log "等待 mysql 就绪"
    local pw; pw="$(mysql_root_pw)"
    for i in $(seq 1 60); do
        if docker exec stack-mysql mysqladmin ping -uroot -p"$pw" --silent >/dev/null 2>&1; then
            log "mysql 已就绪"
            return 0
        fi
        sleep 2
    done
    err "mysql 60s 内未就绪"; exit 1
}

create_db() {
    log "确保库与账号存在（幂等）"
    local pw; pw="$(mysql_root_pw)"
    docker exec stack-mysql mysql -uroot -p"$pw" -e "
CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USERNAME'@'%' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'%';
FLUSH PRIVILEGES;"
}

# ============================================================================
# 2. 代码
# ============================================================================
fetch_code() {
    if [ ! -d "$PROJECT_HOST_DIR/.git" ]; then
        if [ -n "$GIT_REPO" ] && [ "$PROJECT_HOST_DIR" != "$SCRIPT_DIR" ]; then
            log "克隆代码 → $PROJECT_HOST_DIR"
            git clone -b "$GIT_BRANCH" "$GIT_REPO" "$PROJECT_HOST_DIR"
        else
            log "目录不是 git 仓库，跳过拉取（请自行确保代码到位）"
        fi
        return
    fi

    log "拉取最新代码（$GIT_BRANCH）"
    git -C "$PROJECT_HOST_DIR" fetch --all --prune
    git -C "$PROJECT_HOST_DIR" checkout "$GIT_BRANCH"
    git -C "$PROJECT_HOST_DIR" pull --ff-only origin "$GIT_BRANCH"
}

# ============================================================================
# 3. 各份 .env
# ============================================================================
write_envs() {
    local env_file="$PROJECT_HOST_DIR/.env"

    local apikey reverb_key reverb_secret token vite_port wss_url tmpl
    apikey="${EXTERNAL_API_KEY:-$(rand)}"
    reverb_key="${REVERB_APP_KEY:-$(rand)}"
    reverb_secret="${REVERB_APP_SECRET:-$(rand)}"
    token="${TELETHON_CALLBACK_TOKEN:-$(rand)}"
    vite_port=$([ "$PROTO" = "https" ] && echo 443 || echo 80)
    wss_url=$([ "$PROTO" = "https" ] && echo "wss://$DOMAIN/app" || echo "ws://$DOMAIN/app")
    tmpl="$(mktemp)"

    # 老 .env 里已有主密钥时，派生值必须沿用同一套，不能另生成
    # （否则 EXTERNAL_API_KEY 与 VITE_API_KEY、REVERB_APP_KEY 与 VITE_REVERB_APP_KEY 对不上）
    local cur_apikey cur_rkey cur_rsecret
    cur_apikey="$(env_val "$env_file" EXTERNAL_API_KEY)"
    cur_rkey="$(env_val "$env_file" REVERB_APP_KEY)"
    cur_rsecret="$(env_val "$env_file" REVERB_APP_SECRET)"
    if [ -n "$cur_apikey" ];  then apikey="$cur_apikey";         fi
    if [ -n "$cur_rkey" ];    then reverb_key="$cur_rkey";       fi
    if [ -n "$cur_rsecret" ]; then reverb_secret="$cur_rsecret"; fi

    # ---- 静态默认值：与仓库根目录 .env.example 逐键对齐 ----
    # 用 'ENVTMPL'（带引号）包起来，里面的 $ 不展开，原样落盘。
    # 新增变量时改这一处即可，新装和「老 .env 补齐」两条路径共用。
    cat > "$tmpl" <<'ENVTMPL'
APP_NAME=机器人管理系统
APP_ENV=production
APP_KEY=
APP_DEBUG=false

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306

BROADCAST_DRIVER=reverb
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
SESSION_DRIVER=file
SESSION_LIFETIME=120

MEMCACHED_HOST=127.0.0.1

REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_CLIENT=phpredis

MAIL_MAILER=smtp
MAIL_HOST=mailhog
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME=机器人管理系统

# S3 / 通用 AWS 凭证（下面的 OCR 复用同一对 key，所以只写一份）
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

# Pusher（本项目实际用 Reverb，这里只占位，保持与 .env.example 一致）
PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_HOST=
PUSHER_PORT=443
PUSHER_SCHEME=https
PUSHER_APP_CLUSTER=mt1
VITE_PUSHER_APP_KEY=
VITE_PUSHER_HOST=
VITE_PUSHER_PORT=
VITE_PUSHER_SCHEME=
VITE_PUSHER_APP_CLUSTER=

ALIOSS_BUCKET=
ALIOSS_ACCESS_ID=
ALIOSS_ACCESS_SECRET=
ALIOSS_ENDPOINT=
ALIOSS_UPLOAD_DIR=

# Telegram 业务开关
TELEGRAM_MEDIA_DOWNLOAD_TIMEOUT=30
TELEGRAM_QUEUE_DEFAULT=default
TELEGRAM_QUEUE_OCR=ocr
TELEGRAM_TRANSLATE_ENABLED=true
TELEGRAM_TRANSLATE_SOURCE=zh
TELEGRAM_TRANSLATE_CACHE_TTL=43200

# OCR / 翻译（AWS Textract / Translate）
AWS_REGION=us-east-1
AWS_OCR_DEFAULT=textract
AWS_OCR_TIMEOUT=15
AWS_OCR_MAX_BYTES=9437184
AWS_TRANSLATE_TIMEOUT=10

# Telethon（Python MTProto 服务）
TELETHON_BASE_URL=http://telegram-py:8081
TELETHON_TIMEOUT=120
TELETHON_MEDIA_PATH=/data/media

# 回调验签（新接入的上游建议配）
TELEGRAM_HOOK_SECRET=
TELEGRAM_HOOK_SIGN_ALGO=hmac_sha256
TELEGRAM_HOOK_SIGN_FIELD=sign
TELEGRAM_HOOK_SIGN_TTL=300
TELEGRAM_INTERACTIVE_TTL=86400
ENVTMPL

    # ---- Laravel .env ----
    if [ ! -s "$env_file" ]; then
        log "生成 Laravel .env"
        cat "$tmpl" > "$env_file"

        # 部署参数派生段（域名 / 数据库 / 随机密钥）
        cat >> "$env_file" <<EOF

APP_URL=$APP_URL

DB_DATABASE=$DB_DATABASE
DB_USERNAME=$DB_USERNAME
DB_PASSWORD=$DB_PASSWORD
DB_PREFIX=$DB_PREFIX

# 外部 API 校验密钥（与前端 VITE_API_KEY 一致）
EXTERNAL_API_KEY=$apikey

# Reverb WebSocket（走主域名 /app，由 Nginx 反代到 reverb:8080）
REVERB_APP_ID=$REVERB_APP_ID
REVERB_APP_KEY=$reverb_key
REVERB_APP_SECRET=$reverb_secret
REVERB_HOST=$DOMAIN
REVERB_PORT=8080
REVERB_SCHEME=http

# 前端（构建时注入，改完必须重新 build）
VITE_BASE_URL=$APP_URL/api/
VITE_APP_NAME=机器人后台管理
VITE_REVERB_APP_KEY=$reverb_key
VITE_REVERB_HOST=$DOMAIN
VITE_REVERB_PORT=$vite_port
VITE_REVERB_SCHEME=$PROTO
VITE_WSS_URL=$wss_url
VITE_API_KEY=$apikey

# 浏览器 -> Nginx -> PHP 这一跳用（容器间走服务名，不受影响）
SANCTUM_STATEFUL_DOMAINS=$DOMAIN
SESSION_DOMAIN=$DOMAIN

# 本地 Telegram Bot API Server（TDLib）身份：my.telegram.org 申请。
# 留空的话 tgbot-bot-api 容器会起来就退出；不用本地 Bot API 可以先不管。
TELEGRAM_API_ID=$TELEGRAM_API_ID
TELEGRAM_API_HASH=$TELEGRAM_API_HASH

# 留空 = 走官方 https://api.telegram.org/bot
# 启用本地 TDLib 时填 http://telegram-bot-api:8083/bot
TELEGRAM_BASE_BOT_URL=

# Telethon 回调密钥（telegram-service/.env 的 CALLBACK_TOKEN 用同一个）
TELETHON_CALLBACK_TOKEN=$token

# compose 变量（部署脚本写入，勿删）
PROJECTS_ROOT=$PROJECTS_ROOT
PROJECT_DIR=$PROJECT_DIR
EOF
        log "Laravel .env 已生成（APP_KEY 待容器启动后生成）"
    else
        # 已存在：只补 .env.example 里有、而本地没有的键，已有的值一律不动
        log "Laravel .env 已存在，补齐缺失项（对齐 .env.example）"
        while IFS='=' read -r k v; do
            case "$k" in
                ''|\#*) continue ;;
            esac
            grep -q "^${k}=" "$env_file" || printf '%s=%s\n' "$k" "$v" >> "$env_file"
        done < "$tmpl"
    fi
    rm -f "$tmpl"

    # ---- 部署参数类：缺失才补，已存在保留原值 ----
    ensure_env_default "$env_file" APP_URL                  "$APP_URL"
    ensure_env_default "$env_file" DB_DATABASE              "$DB_DATABASE"
    ensure_env_default "$env_file" DB_USERNAME              "$DB_USERNAME"
    ensure_env_default "$env_file" DB_PASSWORD              "$DB_PASSWORD"
    ensure_env_default "$env_file" DB_PREFIX                "$DB_PREFIX"
    ensure_env_default "$env_file" EXTERNAL_API_KEY         "$apikey"
    ensure_env_default "$env_file" REVERB_APP_ID            "$REVERB_APP_ID"
    ensure_env_default "$env_file" REVERB_APP_KEY           "$reverb_key"
    ensure_env_default "$env_file" REVERB_APP_SECRET        "$reverb_secret"
    ensure_env_default "$env_file" REVERB_HOST              "$DOMAIN"
    ensure_env_default "$env_file" REVERB_PORT              8080
    ensure_env_default "$env_file" REVERB_SCHEME            http
    ensure_env_default "$env_file" VITE_BASE_URL            "$APP_URL/api/"
    ensure_env_default "$env_file" VITE_APP_NAME            "机器人后台管理"
    ensure_env_default "$env_file" VITE_REVERB_APP_KEY      "$reverb_key"
    ensure_env_default "$env_file" VITE_REVERB_HOST         "$DOMAIN"
    ensure_env_default "$env_file" VITE_REVERB_PORT         "$vite_port"
    ensure_env_default "$env_file" VITE_REVERB_SCHEME       "$PROTO"
    ensure_env_default "$env_file" VITE_WSS_URL             "$wss_url"
    ensure_env_default "$env_file" VITE_API_KEY             "$apikey"
    ensure_env_default "$env_file" SANCTUM_STATEFUL_DOMAINS "$DOMAIN"
    ensure_env_default "$env_file" SESSION_DOMAIN           "$DOMAIN"
    ensure_env_default "$env_file" TELEGRAM_API_ID          "$TELEGRAM_API_ID"
    ensure_env_default "$env_file" TELEGRAM_API_HASH        "$TELEGRAM_API_HASH"
    ensure_env_default "$env_file" TELEGRAM_BASE_BOT_URL    ""

    # ---- docker-compose.yml 用 .env 做变量替换，这几个必须与实际路径/密钥一致 ----
    ensure_env_var "$env_file" PROJECTS_ROOT "$PROJECTS_ROOT"
    ensure_env_var "$env_file" PROJECT_DIR   "$PROJECT_DIR"

    # Telethon 回调要带能命中本项目 vhost 的 Host：生产 vhost 的 server_name 就是 $DOMAIN。
    # 写死 tgbot.local 的话，一旦那份开发 vhost 不在，请求会落到 default_server 返回 404。
    ensure_env_var "$env_file" TELETHON_CALLBACK_HOST "$DOMAIN"

    local cb_token
    cb_token="$(grep -m1 '^TELETHON_CALLBACK_TOKEN=' "$env_file" | cut -d= -f2- || true)"
    [ -n "$cb_token" ] || cb_token="$token"
    ensure_env_var "$env_file" TELETHON_CALLBACK_TOKEN "$cb_token"

    if [ -z "${TELEGRAM_API_ID:-}" ] || [ -z "${TELEGRAM_API_HASH:-}" ]; then
        warn "未配置 TELEGRAM_API_ID / TELEGRAM_API_HASH：tgbot-bot-api 容器会启动即退出"
        warn "  → my.telegram.org 申请后填进 $env_file，再 docker compose up -d telegram-bot-api"
    fi

    # ---- 前端生产环境 ----
    log "写入 web/.env.production"
    local reverb_key_v
    reverb_key_v="$(grep -m1 '^REVERB_APP_KEY=' "$PROJECT_HOST_DIR/.env" | cut -d= -f2- || true)"
    local apikey_v
    apikey_v="$(grep -m1 '^EXTERNAL_API_KEY=' "$PROJECT_HOST_DIR/.env" | cut -d= -f2- || true)"
    cat > "$PROJECT_HOST_DIR/web/.env.production" <<EOF
VITE_BASE_URL=$APP_URL/api
VITE_APP_NAME=机器人后台管理
VITE_REVERB_APP_KEY=$reverb_key_v
VITE_REVERB_HOST=$DOMAIN
VITE_REVERB_PORT=$([ "$PROTO" = "https" ] && echo 443 || echo 80)
VITE_REVERB_SCHEME=$PROTO
VITE_REVERB_USE_TLS=$([ "$PROTO" = "https" ] && echo true || echo false)
VITE_WSS_URL=$([ "$PROTO" = "https" ] && echo "wss://$DOMAIN/app" || echo "ws://$DOMAIN/app")
VITE_API_KEY=$apikey_v
EOF

    # ---- Telethon ----
    log "写入 telegram-service/.env"
    local token_v
    token_v="$(grep -m1 '^TELETHON_CALLBACK_TOKEN=' "$PROJECT_HOST_DIR/.env" | cut -d= -f2- || true)"
    cat > "$PROJECT_HOST_DIR/telegram-service/.env" <<EOF
MEDIA_DIR=/data/media
SESSIONS_DIR=/data/sessions
LARAVEL_BASE_URL=http://stack-nginx
LARAVEL_HOST=$DOMAIN
CALLBACK_TOKEN=$token_v
REDIS_URL=redis://redis:6379/0
EMOJI_SWITCH_PREFIX=telegram_group_collect_emojis_
LOG_LEVEL=INFO
EOF
}

# ============================================================================
# 4. Nginx vhost
# ============================================================================
write_nginx_conf() {
    log "生成 Nginx vhost → $STACK_ROOT/conf/nginx/sites/${PROJECT_DIR}.conf"

    local root_block
    if [ "$SERVE_MODE" = "dist" ]; then
        root_block="    location / {
        root $CONTAINER_DIR/web/dist;
        try_files \$uri \$uri/ /index.html;
    }

    location ~* \\\.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?|ttf|eot)\$ {
        root $CONTAINER_DIR/web/dist;
        expires 7d;
        add_header Cache-Control \"public\";
    }"
    else
        root_block="    location / {
        set                    \$frontend_upstream \"tgbot-frontend:5173\";
        proxy_pass             http://\$frontend_upstream;
        proxy_http_version     1.1;
        proxy_set_header       Host \$host;
        proxy_set_header       X-Real-IP \$remote_addr;
        proxy_set_header       X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header       X-Forwarded-Proto \$scheme;
        proxy_set_header       Upgrade \$http_upgrade;
        proxy_set_header       Connection \"upgrade\";
        proxy_read_timeout     3600s;
    }"
    fi

    local listen_block="    listen 80;"
    if [ "$ENABLE_SSL" = "1" ]; then
        listen_block="    listen 80;
    listen 443 ssl;
    ssl_certificate     $SSL_CERT;
    ssl_certificate_key $SSL_KEY;"
    fi

    cat > "$STACK_ROOT/conf/nginx/sites/${PROJECT_DIR}.conf" <<EOF
# 由 deploy.sh 自动生成，勿手改（改 deploy.env 后重跑 ./deploy.sh app）
server {
$listen_block
    server_name $DOMAIN;

    client_max_body_size 50m;

$root_block

    # ---- API：交给 php-fpm ----
    location /api {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # 上游写成变量：nginx 只在启动/重载时解析写死的名字，php 容器一重建
    # IP 就变了，nginx 还发往旧 IP → /api/* 全 502（php-fpm 日志却是干净的）。
    # 变量形式会按 nginx.conf 里 resolver 的 valid 周期重新解析。
    location ~ \\.php\$ {
        set            \$php_upstream \"tgbot-php:9000\";
        fastcgi_pass   \$php_upstream;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME $CONTAINER_DIR/public\$fastcgi_script_name;
        include        fastcgi_params;
    }

    # ---- Reverb WebSocket 反代 ----
    location /app {
        set                    \$reverb_upstream \"tgbot-reverb:8080\";
        proxy_pass             http://\$reverb_upstream;
        proxy_http_version     1.1;
        proxy_set_header       Upgrade \$http_upgrade;
        proxy_set_header       Connection "Upgrade";
        proxy_set_header       Host \$host;
        proxy_read_timeout     3600s;
        proxy_send_timeout     3600s;
    }
}
EOF

    if [ "$ENABLE_SSL" = "1" ] && [ "$PROTO" = "https" ]; then
        log "HTTPS 已开启：请确保证书存在 $SSL_CERT / $SSL_KEY"
    fi
}

# nginx 容器名在不同部署方式下不一样：
#   项目自带 nginx（老部署）  → tgbot-nginx
#   共享栈 nginx（deploy.sh） → stack-nginx
# 写死任何一个，在另一种部署上 `docker exec` 都会失败；原来失败只打一行 warn，
# 于是「脚本跑完了但 nginx 根本没重载」，php 容器一重建 IP 变了就全站 502。
detect_nginx_container() {
    local name
    for name in tgbot-nginx stack-nginx nginx; do
        if docker inspect "$name" >/dev/null 2>&1; then
            printf '%s' "$name"
            return 0
        fi
    done
    return 1
}

reload_nginx() {
    local nginx_container

    if ! nginx_container="$(detect_nginx_container)"; then
        err "找不到 nginx 容器（tgbot-nginx / stack-nginx / nginx 都不存在）"
        err "nginx 不重载 = 上游 IP 不刷新，重建 php 后 /api/* 必然 502"
        exit 1
    fi

    log "重载 Nginx（$nginx_container）"

    if ! docker exec "$nginx_container" nginx -t >/dev/null 2>&1; then
        err "nginx -t 未通过，配置有错，已中止（完整报错如下）"
        docker exec "$nginx_container" nginx -t || true
        exit 1
    fi

    docker exec "$nginx_container" nginx -s reload
    log "✅ Nginx 已重载（上游 IP 已刷新）"
}

# ============================================================================
# 5. 项目容器与后端
# ============================================================================

# bootstrap/cache 与 storage/framework/* 被 .gitignore 忽略，全新代码里不存在；
# 且 docker exec 是 root、php-fpm 跑 www-data，不建目录/不改属主会报
# "The bootstrap/cache directory must be present and writable"。
ensure_writable_dirs() {
    log "确保 storage / bootstrap/cache 存在且属主为 www-data（php-fpm 运行用户）"
    php_exec "mkdir -p bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        && chown -R www-data:www-data storage bootstrap/cache \
        && chmod -R ug+rwX storage bootstrap/cache"
}

# CatchAdmin 安装 / 升级（见 docs/deployment.md 第 3 节）
#
# app:install（app/Console/Commands/InstallApp.php）一步到位，内部依次：
#   1) 核心 migrate --force
#   2) 按 permissions → user → develop → cms → system → telegram 顺序
#      app:module:install（未注册则注册+迁移+seed；已注册则跳过 create，只补跑迁移+seed）
#   3) 各模块 seed（后台菜单/权限数据）+ 菜单可见性修正
# 幂等、可安全重复执行，且会绕过 production 下 migrate 的交互确认。
#
# ⚠️ 不要用 catch:install / catch:module:install：
#    它们在 production 下 migrate 会被确认拦截而静默不建表，
#    紧接着 seed 报 1146（表象是表不存在，实际是迁移被取消）。
#
# 额外一步：app:install 是「先核心 migrate、后装模块」，而核心迁移
# add_unique_username_to_users 会直接查 users 表，users 却在 User 模块里，
# 全新库上会报 1146。所以先单独把 user 模块迁移跑掉（无外部依赖，可安全先跑）。
run_install() {
    log "User 模块迁移（先建 users 表，供核心迁移使用）"
    php_exec "php artisan catch:migrate user --force"

    log "app:install（核心迁移 + 全部模块迁移与 seed）"
    php_exec "php artisan app:install"

    log "一次性命令"
    php_exec "php artisan storage:link"
}

deploy_app() {
    write_envs
    write_nginx_conf

    log "构建 Telethon 镜像（本地构建，不去 registry 拉）"
    app_compose build telegram-py

    remove_shared_redis

    # 先只起 php：queue / queue-ocr / reverb 的 command 就是 php artisan ...，
    # vendor 还没装时它们会报 "Failed opening required .../vendor/autoload.php"，
    # 而 restart: unless-stopped 会让它们崩溃→重启→再崩溃，首次部署刷满屏日志。
    # 等 composer install 完再 up 全部，就不会有这段空转。
    log "启动 php（先装依赖，再起常驻容器）"
    app_compose up -d php

    ensure_writable_dirs

    log "安装 PHP 依赖"
    php_exec "COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader"

    if ! grep -q '^APP_KEY=base64:' "$PROJECT_HOST_DIR/.env" 2>/dev/null; then
        log "生成 APP_KEY"
        php_exec "php artisan key:generate --force"
    fi

    log "启动其余容器（queue / queue-ocr / reverb / frontend / telegram-py …）"
    # 若这一步重建了 php，它的内网 IP 会变 → 靠后面的 reload_nginx() 刷新 nginx；
    # 别把 reload 那一步删掉。
    app_compose up -d

    run_install

    log "刷新缓存"
    php_exec "php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache"

    log "同步功能与平台接口"
    php_exec "php artisan telegram:sync-features"

    # 上面是 root 执行的，缓存文件会变成 root 属主，php-fpm(www-data) 写不了
    php_exec "chown -R www-data:www-data storage bootstrap/cache"

    build_frontend

    log "重启常驻容器（加载新代码）"
    app_compose restart queue queue-ocr reverb

    reload_nginx

    log "✅ 项目部署完成"
}

build_frontend() {
    # frontend 容器本身不做构建（command 只装依赖后挂住），这里按需执行一次 yarn build。
    # 必须在容器内构建：node_modules 是独立命名卷，宿主机上没有。
    log "构建前端（一次性，构建完就结束，不留常驻编译进程）"
    docker exec tgbot-frontend sh -c "yarn install && yarn build"
    log "前端产物：$(ls -1 "$PROJECT_HOST_DIR/web/dist" 2>/dev/null | wc -l) 个文件"
}

# ============================================================================
# 6. 更新
# ============================================================================
update_app() {
    fetch_code
    ensure_writable_dirs

    log "更新依赖"
    php_exec "COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader"

    run_install

    log "刷新缓存"
    php_exec "php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache"

    log "同步功能与平台接口"
    php_exec "php artisan telegram:sync-features"
    php_exec "chown -R www-data:www-data storage bootstrap/cache"

    build_frontend

    # docker-compose.yml 可能改过（新增服务、换镜像 tag）：先按新配置对齐容器。
    # 注意：这一步重建 php 会换掉它的内网 IP，所以后面必须 reload nginx，
    # 否则 nginx 还往旧 IP 发请求 → /api/* 全 502。
    log "按 compose 配置对齐容器"
    app_compose up -d

    log "重启常驻容器"
    app_compose restart queue queue-ocr reverb

    reload_nginx

    log "✅ 更新完成"
}

# ============================================================================
# 7. 状态
# ============================================================================
show_status() {
    log "共享栈："
    stack_compose ps || true
    log "项目容器："
    app_compose ps || true
    log "健康检查：$APP_URL"
    curl -sS -o /dev/null -w "  首页 HTTP %{http_code}\n" "$APP_URL/" || true
    log "（后台接口需要登录，返回 401 也说明路由与 php-fpm 正常）"
}

# ============================================================================
case "${1:-all}" in
    all)    deploy_stack; fetch_code; deploy_app; show_status ;;
    stack)  deploy_stack ;;
    app)    fetch_code; deploy_app; show_status ;;
    update) update_app; show_status ;;
    build)  build_frontend ;;
    status) show_status ;;
    *)
        echo "用法: $0 {all|stack|app|update|build|status}"
        exit 1
        ;;
esac

log "域名：$APP_URL"
log "提示：机器人 Webhook 需要在后台逐个机器人设置（PUT /api/telegram/webhook/set/{botId}），回调地址即 $APP_URL/api/telegram/webhook/pull"
