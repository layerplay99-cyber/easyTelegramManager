#!/usr/bin/env bash
# ============================================================================
# telegram-bot —— 测试 / 共享环境一键部署（不修改生产部署）
# ----------------------------------------------------------------------------
# 适用场景：本机已有别的 nginx 占着宿主机 80/443（如 easycall-nginx），
#           或你就是不想让本项目直接对外暴露 80/443。
#
# 思路：
#   - 共享栈只起 mysql / redis（不起栈的 nginx，避免抢 80/443）
#   - 本项目在 stack 网络里起一个「只对内」的 nginx（tgbot-nginx，不映射宿主端口）
#   - 你现有的边缘 nginx（默认 easycall-nginx）反代到 tgbot-nginx:80
#
# 用法：
#   cp deploy.env.test.example deploy.env.test   # 只改 DOMAIN（测试域名）
#   chmod +x deploy-test.sh
#   ./deploy-test.sh all      # 首次全量
#   ./deploy-test.sh update   # 后续更新
#   ./deploy-test.sh status   # 看状态
#
# 幂等：可重复执行，已存在的库 / .env / 配置会跳过或覆盖。
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------- 外部配置（存在则覆盖默认值）----------------
if [ -f "$SCRIPT_DIR/deploy.env.test" ]; then
    set -a
    # shellcheck disable=SC1091
    . "$SCRIPT_DIR/deploy.env.test"
    set +a
fi

# ============================================================================
# 配置区
# ============================================================================

# ★ 测试域名（边缘 nginx 用 server_name 匹配，改这个即可）
DOMAIN="${DOMAIN:-bot-test.example.com}"

PROTO="${PROTO:-https}"
# 本项目内部 nginx 不做 TLS（TLS 在边缘 nginx 终止），这里固定 80
SERVE_MODE="${SERVE_MODE:-dist}"

PROJECT_HOST_DIR="${PROJECT_HOST_DIR:-$SCRIPT_DIR}"
PROJECT_DIR="${PROJECT_DIR:-$(basename "$PROJECT_HOST_DIR")}"
PROJECTS_ROOT="${PROJECTS_ROOT:-$(dirname "$PROJECT_HOST_DIR")}"

STACK_ROOT="${STACK_ROOT:-/opt/docker-stack}"
STACK_SRC="${STACK_SRC:-$SCRIPT_DIR/docker-stack}"

GIT_REPO="${GIT_REPO:-https://github.com/layerplay99-cyber/easyTelegramManager.git}"
GIT_BRANCH="${GIT_BRANCH:-main}"

# 测试库（与生产隔离）
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-root}"
DB_DATABASE="${DB_DATABASE:-bots_test}"
DB_USERNAME="${DB_USERNAME:-bots_test}"
DB_PASSWORD="${DB_PASSWORD:-bots_test123}"
DB_PREFIX="${DB_PREFIX:-bmt}"

SKIP_IMAGE_BUILD="${SKIP_IMAGE_BUILD:-0}"

# 边缘 nginx（反代目标）
EDGE_PROXY_TARGET="${EDGE_PROXY_TARGET:-tgbot-nginx:80}"
EDGE_NGINX_CONTAINER="${EDGE_NGINX_CONTAINER:-easycall-nginx}"

EXTERNAL_API_KEY="${EXTERNAL_API_KEY:-}"
TELETHON_CALLBACK_TOKEN="${TELETHON_CALLBACK_TOKEN:-}"
REVERB_APP_ID="${REVERB_APP_ID:-160452}"
REVERB_APP_KEY="${REVERB_APP_KEY:-}"
REVERB_APP_SECRET="${REVERB_APP_SECRET:-}"

# 本地 Telegram Bot API Server（TDLib）身份：my.telegram.org 申请
TELEGRAM_API_ID="${TELEGRAM_API_ID:-}"
TELEGRAM_API_HASH="${TELEGRAM_API_HASH:-}"

# ============================================================================
# 派生变量
# ============================================================================
APP_URL="${PROTO}://${DOMAIN}"
CONTAINER_DIR="/opt/projects/${PROJECT_DIR}"

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    COMPOSE="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE="docker-compose"
else
    echo "❌ 未找到 docker / docker compose"; exit 1
fi

stack_compose() { $COMPOSE -f "$STACK_ROOT/docker-compose.yml" "$@"; }
app_compose()   { $COMPOSE --project-directory "$PROJECT_HOST_DIR" \
                    -f "$PROJECT_HOST_DIR/docker-compose.yml" \
                    -f "$PROJECT_HOST_DIR/docker-compose.test.yml" "$@"; }
php_exec()      { docker exec tgbot-php sh -c "$1"; }   # 多命令必须 sh -c 包起来

log()  { echo -e "\033[32m[test-deploy]\033[0m $*"; }
warn() { echo -e "\033[33m[warn]\033[0m $*"; }
err()  { echo -e "\033[31m[error]\033[0m $*" >&2; }

rand() { head -c 24 /dev/urandom | base64 | tr -d '/+=' | head -c 32; }

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
# 1. 共享栈（只起 mysql，不起 nginx / redis）
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

deploy_stack() {
    log "部署共享栈（仅 mysql/redis）→ $STACK_ROOT"
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

    # 同 deploy.sh：路径写错会让 nginx 挂到空目录（静态文件全读不到 → 500），强制对齐。
    # 填宿主路径（/opt），不是容器内的 /opt/projects。
    ensure_env_var "$STACK_ROOT/.env" STACK_ROOT    "$STACK_ROOT"
    ensure_env_var "$STACK_ROOT/.env" PROJECTS_ROOT "$PROJECTS_ROOT"

    if [ "$SKIP_IMAGE_BUILD" != "1" ]; then
        log "构建公共镜像 telegram-bot-php / telegram-bot-node（首次较慢）"
        stack_compose build php node
    fi

    log "启动 mysql（不起 nginx，避免与现有 80/443 冲突；redis 由项目自带 tgbot-redis 提供）"
    stack_compose up -d mysql

    remove_shared_redis

    wait_mysql
    create_db
    log "✅ 共享栈就绪"
}

# 取正在运行的 stack-mysql 容器的实际 root 密码（避免 .env 与已初始化数据不一致）
mysql_root_pw() {
    docker exec stack-mysql sh -c 'echo "$MYSQL_ROOT_PASSWORD"' 2>/dev/null || echo "$MYSQL_ROOT_PASSWORD"
}

wait_mysql() {
    log "等待 mysql 就绪"
    local pw; pw="$(mysql_root_pw)"
    for i in $(seq 1 60); do
        if docker exec stack-mysql mysqladmin ping -uroot -p"$pw" --silent >/dev/null 2>&1; then
            log "mysql 已就绪"; return 0
        fi
        sleep 2
    done
    err "mysql 60s 内未就绪"; exit 1
}

create_db() {
    log "确保测试库与账号存在（幂等）"
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
# 3. .env（测试）
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
    local cur_apikey cur_rkey cur_rsecret
    cur_apikey="$(env_val "$env_file" EXTERNAL_API_KEY)"
    cur_rkey="$(env_val "$env_file" REVERB_APP_KEY)"
    cur_rsecret="$(env_val "$env_file" REVERB_APP_SECRET)"
    if [ -n "$cur_apikey" ];  then apikey="$cur_apikey";         fi
    if [ -n "$cur_rkey" ];    then reverb_key="$cur_rkey";       fi
    if [ -n "$cur_rsecret" ]; then reverb_secret="$cur_rsecret"; fi

    # ---- 静态默认值：与仓库根目录 .env.example 逐键对齐（与 deploy.sh 同一份清单）----
    cat > "$tmpl" <<'ENVTMPL'
APP_NAME=机器人管理系统(测试)
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
MAIL_FROM_NAME=机器人管理系统(测试)

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

    if [ ! -s "$env_file" ]; then
        log "生成 Laravel .env（测试）"
        cat "$tmpl" > "$env_file"

        cat >> "$env_file" <<EOF

APP_URL=$APP_URL

DB_DATABASE=$DB_DATABASE
DB_USERNAME=$DB_USERNAME
DB_PASSWORD=$DB_PASSWORD
DB_PREFIX=$DB_PREFIX

# 外部 API 校验密钥（与前端 VITE_API_KEY 一致）
EXTERNAL_API_KEY=$apikey

# Reverb WebSocket
REVERB_APP_ID=$REVERB_APP_ID
REVERB_APP_KEY=$reverb_key
REVERB_APP_SECRET=$reverb_secret
REVERB_HOST=$DOMAIN
REVERB_PORT=8080
REVERB_SCHEME=http

# 前端（构建时注入，改完必须重新 build）
VITE_BASE_URL=$APP_URL/api/
VITE_APP_NAME=机器人后台管理(测试)
VITE_REVERB_APP_KEY=$reverb_key
VITE_REVERB_HOST=$DOMAIN
VITE_REVERB_PORT=$vite_port
VITE_REVERB_SCHEME=$PROTO
VITE_WSS_URL=$wss_url
VITE_API_KEY=$apikey

SANCTUM_STATEFUL_DOMAINS=$DOMAIN
SESSION_DOMAIN=$DOMAIN

# 本地 Telegram Bot API Server（TDLib）身份：my.telegram.org 申请
TELEGRAM_API_ID=$TELEGRAM_API_ID
TELEGRAM_API_HASH=$TELEGRAM_API_HASH
# 留空 = 走官方 https://api.telegram.org/bot
TELEGRAM_BASE_BOT_URL=

TELETHON_CALLBACK_TOKEN=$token

# compose 变量（部署脚本写入，勿删）
PROJECTS_ROOT=$PROJECTS_ROOT
PROJECT_DIR=$PROJECT_DIR
EOF
        log "Laravel .env（测试）已生成"
    else
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
    ensure_env_default "$env_file" VITE_APP_NAME            "机器人后台管理(测试)"
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

    # Telethon 回调要带能命中本项目 vhost 的 Host（生产 vhost 的 server_name 就是 $DOMAIN）
    ensure_env_var "$env_file" TELETHON_CALLBACK_HOST "$DOMAIN"

    local cb_token
    cb_token="$(grep -m1 '^TELETHON_CALLBACK_TOKEN=' "$env_file" | cut -d= -f2- || true)"
    [ -n "$cb_token" ] || cb_token="$token"
    ensure_env_var "$env_file" TELETHON_CALLBACK_TOKEN "$cb_token"

    log "写入 web/.env.production（测试）"
    local reverb_key_v apikey_v
    reverb_key_v="$(grep -m1 '^REVERB_APP_KEY=' "$PROJECT_HOST_DIR/.env" | cut -d= -f2- || true)"
    apikey_v="$(grep -m1 '^EXTERNAL_API_KEY=' "$PROJECT_HOST_DIR/.env" | cut -d= -f2- || true)"
    cat > "$PROJECT_HOST_DIR/web/.env.production" <<EOF
VITE_BASE_URL=$APP_URL/api
VITE_APP_NAME=机器人后台管理(测试)
VITE_REVERB_APP_KEY=$reverb_key_v
VITE_REVERB_HOST=$DOMAIN
VITE_REVERB_PORT=$([ "$PROTO" = "https" ] && echo 443 || echo 80)
VITE_REVERB_SCHEME=$PROTO
VITE_REVERB_USE_TLS=$([ "$PROTO" = "https" ] && echo true || echo false)
VITE_WSS_URL=$([ "$PROTO" = "https" ] && echo "wss://$DOMAIN/app" || echo "ws://$DOMAIN/app")
VITE_API_KEY=$apikey_v
EOF

    log "写入 telegram-service/.env（测试）"
    local token_v
    token_v="$(grep -m1 '^TELETHON_CALLBACK_TOKEN=' "$PROJECT_HOST_DIR/.env" | cut -d= -f2- || true)"
    cat > "$PROJECT_HOST_DIR/telegram-service/.env" <<EOF
MEDIA_DIR=/data/media
SESSIONS_DIR=/data/sessions
# 测试环境不启动栈的 nginx，回调走本项目内部 nginx（tgbot-nginx，只对内）
LARAVEL_BASE_URL=http://tgbot-nginx
LARAVEL_HOST=$DOMAIN
CALLBACK_TOKEN=$token_v
REDIS_URL=redis://redis:6379/0
EMOJI_SWITCH_PREFIX=telegram_group_collect_emojis_
LOG_LEVEL=INFO
EOF
}

# ============================================================================
# 4. Nginx（只对内）
# ============================================================================
write_nginx_conf() {
    local out_dir="$PROJECT_HOST_DIR/deploy-output/nginx"
    mkdir -p "$out_dir"
    log "生成内部 Nginx vhost → $out_dir/${PROJECT_DIR}.conf（只监听容器 80，不占宿主端口）"
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
        proxy_pass             http://tgbot-frontend:5173;
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

    cat > "$out_dir/${PROJECT_DIR}.conf" <<EOF
# 由 deploy-test.sh 自动生成（只对内，边缘 nginx 反代过来），勿手改
server {
    listen 80;
    server_name $DOMAIN;

    client_max_body_size 50m;

$root_block

    location /api {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php\$ {
        fastcgi_pass   tgbot-php:9000;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME $CONTAINER_DIR/public\$fastcgi_script_name;
        include        fastcgi_params;
    }

    location /app {
        proxy_pass             http://tgbot-reverb:8080;
        proxy_http_version     1.1;
        proxy_set_header       Upgrade \$http_upgrade;
        proxy_set_header       Connection "Upgrade";
        proxy_set_header       Host \$host;
        proxy_read_timeout     3600s;
        proxy_send_timeout     3600s;
    }
}
EOF

    write_edge_vhost
}

write_edge_vhost() {
    local out_dir="$PROJECT_HOST_DIR/deploy-output"
    mkdir -p "$out_dir"
    log "生成边缘 nginx vhost 片段 → $out_dir/edge-${PROJECT_DIR}.conf"
    cat > "$out_dir/edge-${PROJECT_DIR}.conf" <<EOF
# 由 deploy-test.sh 生成。放到你现有边缘 nginx（默认 $EDGE_NGINX_CONTAINER）的 vhost 目录，
# 并让边缘 nginx 接入 stack 网络： docker network connect stack $EDGE_NGINX_CONTAINER
# 然后： docker exec $EDGE_NGINX_CONTAINER nginx -t && docker exec $EDGE_NGINX_CONTAINER nginx -s reload
#
# 如果边缘已经做 HTTPS（推荐），把下面 80 这段改成 443 + 你的证书即可，proxy_pass 不变。
#
# ⚠️ 用「变量 + resolver」做运行时解析，不要直接写 proxy_pass http://主机:端口：
#    后者 nginx 在加载配置阶段就要解析主机，本项目容器没起来时会导致
#    nginx -t 失败，甚至边缘 nginx 重启时直接起不来（会连带你其它站点一起挂）。
#    127.0.0.11 是 Docker 内嵌 DNS，只在同网络内容器间生效——所以必须先把
#    边缘 nginx 接入 stack 网络：docker network connect stack $EDGE_NGINX_CONTAINER
server {
    listen 80;
    server_name $DOMAIN;

    client_max_body_size 50m;

    resolver 127.0.0.11 valid=30s ipv6=off;
    set \$tgbot_upstream "$EDGE_PROXY_TARGET";

    location / {
        proxy_pass             http://\$tgbot_upstream;
        proxy_http_version     1.1;
        proxy_set_header       Host \$host;
        proxy_set_header       X-Real-IP \$remote_addr;
        proxy_set_header       X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header       X-Forwarded-Proto \$scheme;
        proxy_set_header       Upgrade \$http_upgrade;
        proxy_set_header       Connection "upgrade";
        proxy_read_timeout     3600s;
    }
}
EOF
    log "---- 请在边缘 nginx 上执行（只需一次）----"
    log "docker network connect stack $EDGE_NGINX_CONTAINER || true"
    log "（把 edge-${PROJECT_DIR}.conf 放进边缘 nginx 的 vhost 目录后）"
    log "docker exec $EDGE_NGINX_CONTAINER nginx -t && docker exec $EDGE_NGINX_CONTAINER nginx -s reload"
}

reload_nginx() {
    log "重载本项目内部 Nginx (tgbot-nginx)"
    if docker exec tgbot-nginx nginx -t >/dev/null 2>&1; then
        docker exec tgbot-nginx nginx -s reload
    else
        warn "nginx -t 未通过，稍后手动：docker exec tgbot-nginx nginx -s reload"
    fi
}

# ============================================================================
# 5. 项目容器 + 后端
# ============================================================================

# bootstrap/cache 与 storage/framework/* 被 .gitignore 忽略，全新代码里不存在；
# 且 docker exec 是 root、php-fpm 跑 www-data，不建目录/不改属主会报
# "The bootstrap/cache directory must be present and writable"。
ensure_writable_dirs() {
    log "确保 storage / bootstrap/cache 存在且属主为 www-data（php-fpm 运行用户）"
    # 同 deploy.sh：用绝对路径，docker exec 的工作目录不可靠
    php_exec "mkdir -p $CONTAINER_DIR/bootstrap/cache \
        $CONTAINER_DIR/storage/framework/cache/data \
        $CONTAINER_DIR/storage/framework/sessions \
        $CONTAINER_DIR/storage/framework/testing \
        $CONTAINER_DIR/storage/framework/views \
        $CONTAINER_DIR/storage/logs \
        && chown -R www-data:www-data $CONTAINER_DIR/storage $CONTAINER_DIR/bootstrap/cache \
        && chmod -R ug+rwX $CONTAINER_DIR/storage $CONTAINER_DIR/bootstrap/cache"
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

    # 同 deploy.sh：先只起 php，装完 vendor 再起 queue / reverb，
    # 否则它们会在 composer install 之前空转报 vendor/autoload.php 不存在并反复重启。
    log "启动 php（先装依赖，再起常驻容器）"
    app_compose up -d php

    ensure_writable_dirs

    php_exec "COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader"

    if ! grep -q '^APP_KEY=base64:' "$PROJECT_HOST_DIR/.env" 2>/dev/null; then
        log "生成 APP_KEY"
        php_exec "php artisan key:generate --force"
    fi

    log "启动其余容器（含内部 nginx，不占宿主 80/443）"
    app_compose up -d

    run_install

    log "刷新缓存"
    php_exec "php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache"

    log "同步功能与平台接口"
    php_exec "php artisan telegram:sync-features"

    # 上面是 root 执行的，缓存文件会变成 root 属主，php-fpm(www-data) 写不了
    php_exec "chown -R www-data:www-data $CONTAINER_DIR/storage $CONTAINER_DIR/bootstrap/cache"

    build_frontend

    log "重启常驻容器"
    app_compose restart queue reverb

    reload_nginx
    log "✅ 测试环境部署完成"
}

build_frontend() {
    # 同 deploy.sh：容器内一次性构建，不留常驻的 build --watch 进程
    log "构建前端（一次性）"
    docker exec tgbot-frontend sh -c "yarn install && yarn build"
}

# ============================================================================
# 6. 更新
# ============================================================================
update_app() {
    fetch_code
    ensure_writable_dirs
    php_exec "COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader"
    run_install
    php_exec "php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache"
    php_exec "php artisan telegram:sync-features"
    php_exec "chown -R www-data:www-data $CONTAINER_DIR/storage $CONTAINER_DIR/bootstrap/cache"
    build_frontend
    app_compose restart queue reverb
    reload_nginx
    log "✅ 测试环境更新完成"
}

# ============================================================================
# 7. 状态
# ============================================================================
show_status() {
    log "共享栈："; stack_compose ps || true
    log "项目容器："; app_compose ps || true
    log "健康检查：$APP_URL"
    curl -sS -o /dev/null -w "  首页 HTTP %{http_code}\n" "$APP_URL/" || true
    log "（后台接口需登录，返回 401 也说明路由与 php-fpm 正常）"
}

# ============================================================================
case "${1:-all}" in
    all)    deploy_stack; fetch_code; deploy_app; show_status ;;
    update) update_app; show_status ;;
    status) show_status ;;
    *)
        echo "用法: $0 {all|update|status}"
        exit 1
        ;;
esac

log "域名：$APP_URL"
log "提示：机器人 Webhook 需在后台逐个机器人设置（PUT /api/telegram/webhook/set/{botId}），回调地址即 $APP_URL/api/telegram/webhook/pull"
