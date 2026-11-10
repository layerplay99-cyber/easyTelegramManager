#!/usr/bin/env bash
# ============================================================================
# 把某次提交改动的代码复制到另一个项目目录（目标与本项目不同 git、.env 不同）
#
# 只复制提交里 A/M（新增/修改）的文件，D（删除）的文件在目标侧一并删除，
# 自动跳过与目标环境相关的文件（.env、前端环境、发布产物、上传目录）。
#
# 用法：
#   bash scripts/copy-update-to.sh /path/to/other/project [commit] [--dry-run]
#
#   bash scripts/copy-update-to.sh /d/other/easytg            # 默认复制 eb9b671
#   bash scripts/copy-update-to.sh /d/other/easytg --dry-run  # 只列出，不复制
#
# 复制完成后记得在目标项目里执行（涉及数据库与前端构建）：
#   php artisan catch:migrate user --force
#   php artisan catch:migrate telegram --force
#   php artisan telegram:sync-features
#   （前端）yarn install && yarn build
#   php artisan optimize:clear && php artisan config:cache && php artisan route:cache
# ============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

COMMIT="eb9b671"
DRY_RUN=0
DEST=""

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        # 7~40 位纯十六进制才算 commit，避免把目标路径误当成 commit
        [0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]*) COMMIT="$arg" ;;
        *) DEST="$arg" ;;
    esac
done

[ -n "$DEST" ] || { echo "用法: $0 <目标项目目录> [--dry-run] [commit]"; exit 1; }
[ -d "$DEST" ] || { echo "目标目录不存在: $DEST"; exit 1; }

DEST="$(cd "$DEST" && pwd)"

copied=0; skipped=0; deleted=0

while IFS=$'\t' read -r status file; do
    # 目标环境自己的东西，绝不覆盖
    case "$file" in
        .env|web/.env*|storage/*|bootstrap/cache/*|site/downloads/*|dist/*|vendor/*|node_modules/*)
            skipped=$((skipped+1))
            continue
            ;;
    esac

    case "$status" in
        A|M)
            if [ "$DRY_RUN" = "1" ]; then
                echo "cp  $file"
            else
                mkdir -p "$DEST/$(dirname "$file")"
                cp -f "$file" "$DEST/$file"
            fi
            copied=$((copied+1))
            ;;
        D)
            if [ "$DRY_RUN" = "1" ]; then
                echo "rm  $file"
            else
                rm -f "$DEST/$file"
            fi
            deleted=$((deleted+1))
            ;;
    esac
done < <(git diff-tree --no-commit-id --name-status --no-renames -r "$COMMIT")

echo
echo "提交 $COMMIT → $DEST"
echo "复制 $copied 个文件，删除 $deleted 个，跳过（环境相关）$skipped 个"
[ "$DRY_RUN" = "1" ] && echo "（dry-run：未实际改动目标目录）"
