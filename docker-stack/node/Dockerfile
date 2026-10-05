# 公共 Node 基础镜像：node:20 + 常用全局包管理器
# 构建后打 tag stack-node:20，供所有项目前端容器复用。
FROM node:20-bookworm

# 全局安装 pnpm / yarn，避免每个项目重复安装
RUN npm install -g pnpm@9 yarn

WORKDIR /opt/projects

CMD ["tail", "-f", "/dev/null"]
