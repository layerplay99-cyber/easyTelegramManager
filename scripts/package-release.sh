#!/usr/bin/env bash
# ============================================================================
# 发布打包
# ----------------------------------------------------------------------------
# 产出（默认写到 ./dist）：
#   telegram-bot-<版本>.zip          安装包（install.sh 从下载站拉的就是它）
#   telegram-bot-<版本>.zip.sha256   校验值（install.sh 会自动核对）
#   plugin-template.zip              插件开发模板（下载站提供下载）
#   latest.txt                       最新版本号（install.sh 的 --version latest 读它）
#
# 用法：
#   bash scripts/package-release.sh                     # 版本取日期 20261110
#   bash scripts/package-release.sh --version 1.0.0
#   bash scripts/package-release.sh --with-vendor       # 含 vendor（离线装，包大）
#   bash scripts/package-release.sh --with-dist         # 含 web/dist（跳过前端构建）
#   bash scripts/package-release.sh --publish           # 同时复制到 site/downloads 与 site/install.sh
#
# 默认不含 vendor / web/dist：install.sh 会在目标机的容器里装依赖、构建前端，
# 包体小、下载快。内网离线场景再加 --with-vendor --with-dist。
# ============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="$(date +%Y%m%d)"
WITH_VENDOR=0
WITH_DIST=0
PUBLISH=0

while [ $# -gt 0 ]; do
    case "$1" in
        --version) VERSION="${2:-}"; shift 2 ;;
        --with-vendor) WITH_VENDOR=1; shift ;;
        --with-dist) WITH_DIST=1; shift ;;
        --publish) PUBLISH=1; shift ;;
        -h|--help) sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "未知参数：$1" >&2; exit 1 ;;
    esac
done

DIST="$ROOT/dist"
mkdir -p "$DIST"

PKG="$DIST/telegram-bot-$VERSION.zip"

echo "==> 打包 telegram-bot-$VERSION"

# 排除清单：密钥、依赖、构建产物、运行时数据、历史包
EXCLUDES=(
    ".git/*"
    ".env"
    ".env.backup"
    ".env.production"
    "deploy.env"
    "deploy.env.test"
    "web/.env"
    "web/.env.production"
    "web/.env.development"
    "web/node_modules/*"
    "web/.yarn/*"
    "storage/app/plugins/*"
    "storage/app/plugin*"
    "storage/logs/*"
    "storage/framework/cache/data/*"
    "storage/framework/sessions/*"
    "storage/framework/views/*"
    "storage/*.key"
    "site/downloads/*.zip"
    "site/downloads/*.txt"
    "site/downloads/*.sha256"
    "site/install.sh"
    "dist/*"
    ".codebuddy/*"
    ".idea/*"
    ".vscode/*"
    "*.log"
    "nohup.out"
)

if [ "$WITH_VENDOR" != "1" ]; then
    EXCLUDES+=("vendor/*")
fi

if [ "$WITH_DIST" != "1" ]; then
    EXCLUDES+=("web/dist/*")
fi

EXCLUDE_ARGS=()
for pattern in "${EXCLUDES[@]}"; do
    EXCLUDE_ARGS+=("-x" "$pattern")
done

rm -f "$PKG"
zip -rq "$PKG" . "${EXCLUDE_ARGS[@]}"

SIZE="$(du -h "$PKG" | cut -f1)"
echo "    安装包：$PKG（$SIZE）"

# ---------------------------------------------------------------- 插件模板
TEMPLATE_ZIP="$DIST/plugin-template.zip"
rm -f "$TEMPLATE_ZIP"

# 顶层目录用 demo_hello，安装器解压后会按 manifest.slug 重新落盘
TMP_TEMPLATE="$(mktemp -d)"
mkdir -p "$TMP_TEMPLATE/demo_hello"
cp "$ROOT/resources/plugin-template/manifest.json" "$TMP_TEMPLATE/demo_hello/"
cp "$ROOT/resources/plugin-template/HelloFeature.php" "$TMP_TEMPLATE/demo_hello/"
cp "$ROOT/resources/plugin-template/README.md" "$TMP_TEMPLATE/demo_hello/"

( cd "$TMP_TEMPLATE" && zip -rq "$TEMPLATE_ZIP" demo_hello )
rm -rf "$TMP_TEMPLATE"

echo "    插件模板：$TEMPLATE_ZIP"

# ------------------------------------------------------ 商户示例插件（路线 B 对照示例）
EXAMPLE_ZIP="$DIST/plugin-example-merchant-demo.zip"
rm -f "$EXAMPLE_ZIP"

TMP_EXAMPLE="$(mktemp -d)"
mkdir -p "$TMP_EXAMPLE/merchant_demo"
cp "$ROOT/resources/plugin-examples/merchant-demo/manifest.json" "$TMP_EXAMPLE/merchant_demo/"
cp "$ROOT/resources/plugin-examples/merchant-demo/MerchantDemoFeature.php" "$TMP_EXAMPLE/merchant_demo/"
cp "$ROOT/resources/plugin-examples/merchant-demo/README.md" "$TMP_EXAMPLE/merchant_demo/"

( cd "$TMP_EXAMPLE" && zip -rq "$EXAMPLE_ZIP" merchant_demo )
rm -rf "$TMP_EXAMPLE"

echo "    示例插件：$EXAMPLE_ZIP"

# ---------------------------------------------------------------- 校验与版本
( cd "$DIST" && sha256sum "telegram-bot-$VERSION.zip" > "telegram-bot-$VERSION.zip.sha256" )
printf '%s\n' "$VERSION" > "$DIST/latest.txt"

echo "    校验值：telegram-bot-$VERSION.zip.sha256"
echo "    版本号：latest.txt → $VERSION"

# 小产物随安装包分发：客户机装完后，自己官网的 /site/downloads 也要能下载
# 插件模板、示例插件和安装器（给他们的客户用），否则全是 404。
# 安装包自身与 sha256 不进包（自举）；打包进来的是未注入占位版的 install.sh，
# 客户机官网的安装器按他们自己的域名推断官网根。
mkdir -p "$ROOT/site/downloads"
cp "$TEMPLATE_ZIP" "$ROOT/site/downloads/"
cp "$EXAMPLE_ZIP" "$ROOT/site/downloads/"
cp "$DIST/latest.txt" "$ROOT/site/downloads/latest.txt"
cp "$ROOT/install.sh" "$ROOT/site/install.sh"
zip -q "$PKG" \
    site/downloads/plugin-template.zip \
    site/downloads/plugin-example-merchant-demo.zip \
    site/downloads/latest.txt \
    site/install.sh
echo "    随包分发：模板/示例/版本号/安装器"

# ---------------------------------------------------------------- 发布到站点
if [ "$PUBLISH" = "1" ]; then
    mkdir -p "$ROOT/site/downloads"

    cp "$PKG" "$ROOT/site/downloads/"
    cp "$DIST/telegram-bot-$VERSION.zip.sha256" "$ROOT/site/downloads/"
    cp "$TEMPLATE_ZIP" "$ROOT/site/downloads/"
    cp "$EXAMPLE_ZIP" "$ROOT/site/downloads/"
    cp "$DIST/latest.txt" "$ROOT/site/downloads/latest.txt"

    # 安装器放进官网路径 site/install.sh（客户机 curl <主域>/site/install.sh）
    #
    # 顺手把官网根地址烧进副本（__SITE_URL__，含 /site）：客户机 curl 到的是 /tmp 里的
    # 脚本，它无法知道自己来自哪个域名；不注入就按安装时 --domain 推断 <域名>/site。
    if [ -n "${SITE_URL:-}" ]; then
        sed "s|__SITE_URL__|${SITE_URL%/}|" "$ROOT/install.sh" > "$ROOT/site/install.sh"
        echo "    安装器已注入官网根：${SITE_URL%/}"
    else
        cp "$ROOT/install.sh" "$ROOT/site/install.sh"
        echo "    提示：未设置 SITE_URL，安装器将按 https://<安装域名>/site 推断官网"
    fi

    chmod +x "$ROOT/site/install.sh"

    echo "==> 已发布到 site/"
    ls -lh "$ROOT/site/downloads" | tail -5
else
    echo "==> 未发布（加 --publish 复制到 site/downloads）"
fi
