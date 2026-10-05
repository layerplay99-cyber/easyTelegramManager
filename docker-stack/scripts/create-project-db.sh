#!/usr/bin/env bash
# ============================================================================
# 为新项目在共享 MySQL 上创建「独立 database + 专用账号」
# ----------------------------------------------------------------------------
# 比共享栈 initdb 里的 `stack` 超级用户更细：每个项目一套库 + 账号，互不越权。
#
# 用法：
#   ./create-project-db.sh <project> [db_password]
#     <project>      项目名（同时用作库名前缀 / 账号名，建议小写无特殊字符）
#     [db_password]  可选；不填则随机生成并打印
#
# 部署后记得 chmod +x scripts/*.sh
# ============================================================================
set -euo pipefail

PROJECT="${1:?用法: $0 <project> [db_password]}"
PASS="${2:-$(openssl rand -base64 12)}"
DB="bot_${PROJECT}"      # 库名：bot_<project>，可按需改前缀
USER="${PROJECT}"        # 账号名：与项目同名
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
COMPOSE="$SCRIPT_DIR/../docker-compose.yml"
ROOT_PASS="${MYSQL_ROOT_PASSWORD:-root}"

echo ">> 为项目 '$PROJECT' 创建 MySQL 库 '$DB' 与账号 '$USER' ..."

docker compose -f "$COMPOSE" exec -T mysql \
    mysql -uroot -p"$ROOT_PASS" <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$USER'@'%' IDENTIFIED BY '$PASS';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$USER'@'%';
FLUSH PRIVILEGES;
SQL

echo ">> 完成。请在项目 .env 设置："
echo "     DB_CONNECTION=mysql"
echo "     DB_DATABASE=$DB"
echo "     DB_USERNAME=$USER"
echo "     DB_PASSWORD=$PASS"
