# 文档索引

> Telegram 机器人后台管理系统 · 最后更新：2026-11-10

| 文档 | 内容 |
|---|---|
| [对外 API 标准文档](api.md) | **给三方（上游）对接用**：接入流程、头签名规范、`ping` 自检、业务回调 `hooks/{token}`、幂等与重试、错误码 |
| [部署文档](deployment.md) | 环境要求、Docker 方式（共享栈 + 项目容器）、普通方式（宿主机 LNMP）、`.env` / 前端 / Nginx / Supervisor / 升级、上线检查清单、商户与三方密钥 |
| [功能与插件开发指南](feature-dev.md) | **给写功能的人看**：内部功能 vs 插件两条路怎么选、一个类怎么写（声明/handle/上下文 API）、上游接口与计费、插件打包上传与审核、tinker 直调调试、常见坑速查 |
| [Telegram 模块开发文档](telegram-module.md) | 功能（Features）体系、消息链路、上游回调接入、按钮交互、外部 API 速览、数据隔离、新增功能的步骤、排错 |
| [上游适配器编写指南](upstream-adapter.md) | 「平台 → 上游」出站适配器：一条上游一个文件夹、契约与三个对象、声明表分派、UpstreamRequest API、四个教学 Demo、**完整 ocean 案例**、上线清单 |
| [本地 Telegram Bot API Server](telegram-bot-api-server.md) | 自建 TDLib Bot API 服务（降延迟 + 突破 20MB/50MB 文件限制）、Docker / 源码 / systemd 三种部署、接入本项目（`TELEGRAM_BASE_BOT_URL` 一处切换）、运维与排错 |
| [数据库说明](database.md) | 命名约定、68 张表按业务域清单、核心关联关系、`creator_id` 隔离怎么落库、建表注意事项、常用查询 |
| [平台使用帮助](user-guide.md) | 面向业务人员的操作步骤：从零跑通第一个群（8 步）、各后台页面的具体操作步骤、群内命令速查 |

## 其它位置的文档

| 位置 | 内容 |
|---|---|
| [`docker-stack/README.md`](../docker-stack/README.md) | 公共 Docker 栈（mysql/redis/nginx/php/node）本身怎么部署、怎么接入新项目 |
| [`web/README.md`](../web/README.md) | 前端工程说明 |
| [`modules/*/README.md`](../modules/) | 上游模块自带的说明（Cms / System / Common），非本项目文档 |

## 常用命令速查

```bash
# 跑某个模块的迁移（production 必须 --force）
php artisan catch:migrate telegram --force

# 数据隔离自查：打印某用户能看到哪些角色/部门/岗位，以及被隔离掉的数据
php artisan permissions:check-data-scope <用户名或ID>

# 回填业务数据的 creator_id（先预览，确认后 --apply）
php artisan telegram:backfill-ownership

# 功能列表同步与缓存预热
php artisan telegram:sync-features
php artisan telegram:warm-feature-cache
```

> 模块开发相关细节（写驱动、写转换器、接上游）见
> [Telegram 模块开发文档](telegram-module.md)，不要在代码注释之外再复制一份。
