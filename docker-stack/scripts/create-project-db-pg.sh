#!/usr/bin/env bash
# ============================================================================
# 为新项目在共享 PostgreSQL 上创建「独立 database + 专用角色」
# ----------------------------------------------------------------------------
# PostgreSQL 的账号是集群级（非按库），这里为每个项目建一个专属角色并拥有独立库。
#
# 用法：
#   ./create-project-db-pg.sh <project> [db_password]
# ============================================================================
set -euo pipefail

PROJECT="${1:?用法: $0 <project> [db_password]}"
PASS="${2:-$(openssl rand -base64 12)}"
DB="bot_${PROJECT}"
ROLE="${PROJECT}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
COMPOSE="$SCRIPT_DIR/../docker-compose.yml"

echo ">> 为项目 '$PROJECT' 创建 PostgreSQL 库 '$DB' 与角色 '$ROLE' ..."

# 用 psql 变量（:-v）避免 bash 与 PG 的 $ 符号冲突；DO 块用 $do$ 标签。
docker compose -f "$COMPOSE" exec -T pgsql \
    psql -U stack -v ON_ERROR_STOP=1 -v role="$ROLE" -v db="$DB" -v pw="$PASS" <<'SQL'
DO $do$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname=:'role') THEN
    CREATE ROLE :"role" LOGIN PASSWORD :'pw';
  END IF;
END
$do$;
CREATE DATABASE :"db" OWNER :"role";
GRANT ALL PRIVILEGES ON DATABASE :"db" TO :"role";
SQL

echo ">> 完成。请在项目 .env 设置："
echo "     DB_CONNECTION=pgsql"
echo "     DB_HOST=pgsql"
echo "     DB_PORT=5432"
echo "     DB_DATABASE=$DB"
echo "     DB_USERNAME=$ROLE"
echo "     DB_PASSWORD=$PASS"
