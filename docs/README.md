# 文档索引

> Telegram 机器人后台管理系统 · 最后更新：2026-10-16

| 文档 | 内容 |
|---|---|
| [部署文档](deployment.md) | 环境要求、Docker 方式（共享栈 + 项目容器）、普通方式（宿主机 LNMP）、`.env` / 前端 / Nginx / Supervisor / 升级、上线检查清单 |
| [Telegram 模块开发文档](telegram-module.md) | 功能（Features）体系、消息链路、**上游回调接入**（验签 / 幂等 / 路由表 / 消息转换器）、按钮交互、外部 API、数据隔离、新增功能的步骤、排错 |
| [数据库说明](database.md) | 命名约定、76 张表按业务域清单、核心关联关系、`creator_id` 隔离怎么落库、建表注意事项、常用查询 |

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
