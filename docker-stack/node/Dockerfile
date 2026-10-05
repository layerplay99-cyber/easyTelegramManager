# 公共 Node 基础镜像：node:20 + 常用全局包管理器
# 构建后打 tag stack-node:20，供所有项目前端容器复用。
FROM node:20-bookworm

# node:20 自带 yarn 1.x(classic)，但本项目前端用 Yarn Berry(>=2，见 web/.yarnrc.yml 的 nodeLinker)。
# classic 读不了 berry 的 yarn.lock，所以必须用 corepack 启用 berry（避免 npm 直接装 yarn 与预装 classic 冲突 EEXIST）。
RUN corepack enable && corepack prepare yarn@4.0.0 --activate

# 通过 corepack 启用 pnpm（node:20 已带 pnpm shim，用 prepare 指定版本避免 npm 直接装冲突 EEXIST）
RUN corepack prepare pnpm@9 --activate

WORKDIR /opt/projects

CMD ["tail", "-f", "/dev/null"]
