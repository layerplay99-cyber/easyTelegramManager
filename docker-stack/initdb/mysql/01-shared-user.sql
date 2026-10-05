-- 共享栈 MySQL 初始化
-- 创建一个可跨库建库的超级用户 `stack`，供各项目自行建库（无需动用 root）。
-- 注意：MYSQL_DATABASE / MYSQL_USER / MYSQL_PASSWORD 已由 compose 环境变量自动创建，
--       这里仅补充一个共享的管理账户。

CREATE USER IF NOT EXISTS 'stack'@'%' IDENTIFIED BY 'stack123456';
GRANT ALL PRIVILEGES ON *.* TO 'stack'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
