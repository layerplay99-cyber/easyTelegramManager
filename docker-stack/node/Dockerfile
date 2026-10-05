# 公共 Node 基础镜像：node:20 + 常用全局包管理器
# 构建后打 tag stack-node:20，供所有项目前端容器复用。
FROM node:20-bookworm

# node:20 基础镜像已自带 yarn，只需补充安装 pnpm（避免重装 yarn 升级成 berry v4）
RUN npm install -g pnpm@9

WORKDIR /opt/projects

CMD ["tail", "-f", "/dev/null"]
