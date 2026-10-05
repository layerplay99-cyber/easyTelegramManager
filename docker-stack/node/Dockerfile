# 公共 Node 基础镜像：node:20 + 常用全局包管理器
# 构建后打 tag stack-node:20，供所有项目前端容器复用。
FROM node:20-bookworm

# node:20 自带 yarn 1.x(classic)，但本项目前端用 Yarn Berry(>=2，见 web/.yarnrc.yml 的 nodeLinker)。
# classic 读不了 berry 的 yarn.lock，所以必须用 corepack 启用 berry（避免 npm 直接装 yarn 与预装 classic 冲突 EEXIST）。
RUN corepack enable && corepack prepare yarn@4.0.0 --activate

# 保留 pnpm 作通用能力（项目同时含 pnpm-lock.yaml，但主锁文件是 yarn）
RUN npm install -g pnpm@9

WORKDIR /opt/projects

CMD ["tail", "-f", "/dev/null"]
