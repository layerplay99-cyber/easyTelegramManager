# 部署文档：Telegram 机器人后台管理系统

> 最后更新：2026-09-26
> 后端 Laravel 12 + CatchAdmin（PHP 8.2），前端 Vue3 + Vite（目录 `web/`，构建产物 `web/dist`）

---

## 1. 环境要求

| 项 | 要求 |
|---|---|
| 系统 | Ubuntu 22.04 LTS |
| PHP | ≥ 8.2，需扩展：`pdo_mysql` `redis` `zip` `gd` `mbstring` `bcmath` `xml` `curl` `intl` `fileinfo` |
| 数据库 | MySQL ≥ 8.0 |
| 缓存 / 队列 | Redis ≥ 6 |
| Web | Nginx ≥ 1.20 + php-fpm |
| 进程管理 | Supervisor ≥ 4 |
| 前端构建 | Node ≥ 18（yarn / npm） |
| 其它 | `tesseract-ocr`（OCR 功能依赖） |

```bash
sudo apt install -y php8.2-cli php8.2-fpm php8.2-mysql php8.2-redis php8.2-zip \
  php8.2-gd php8.2-mbstring php8.2-bcmath php8.2-xml php8.2-curl php8.2-intl \
  nginx redis-server supervisor tesseract-ocr
```

---

## 2. 获取代码

```bash
cd /var/www
git clone https://github.com/layerplay99-cyber/easyTelegramManager.git telegram-bot
cd telegram-bot
# 更新：git pull origin main
```

---

## 3. 后端安装

```bash
composer install --no-dev --optimize-autoloader

cp .env.example .env      # 必须先有 .env，否则 catch:install 会进入交互式建库流程
cp .env .env.bak          # catch:install 出错时会删 .env，先备份
# 按第 4 节填好 .env（尤其 DB_*、APP_URL、REVERB_*）

php artisan catch:install
php artisan catch:module:install cms
php artisan catch:module:install system
php artisan catch:module:install telegram
php artisan telegram:scan-activity

php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

- `catch:install` 已完成：`key:generate`、发布配置、`user`/`develop` 迁移与 seed、安装 `permissions` 模块。
  **`cms` / `system` / `telegram` 必须再单独安装**，否则后台对应菜单、路由、权限都不会注册。
- 初始账号：`catch@admin.com` / `catchadmin`，上线后立即改密码。
- 模块安装结果写入 `storage/app/modules.json`（不进 git，由命令生成）；丢失后重跑对应
  `php artisan catch:module:install {module}` 可重建。
- ⚠️ `catch:install` 会用 vendor 版本**强制覆盖 `config/catch.php`**（`vendor:publish --tag=catch-config --force`），
  本项目对该文件的定制（`LocaleMiddleware`、`listen_db_log=false`、`super_admin` 走 env）会被还原成框架默认值。
  装完请确认该文件仍是仓库版本，若被覆盖需恢复并 `php artisan config:clear`。
- 部署**不要用 `--reinstall`**（会 DROP 整个数据库）。
- 仓库需带 `web/` 目录，缺失时 `catch:install` 会尝试从 Gitee 克隆前端。

---

## 4. `.env` 关键配置

| 变量 | 生产取值 |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://bot.dg178.top` |
| `DB_HOST` `DB_PORT` `DB_DATABASE` `DB_USERNAME` `DB_PASSWORD` `DB_PREFIX` | 实际 MySQL（前缀沿用 `bm`） |
| `BROADCAST_DRIVER` | `reverb` |
| `QUEUE_CONNECTION` | `redis` |
| `CACHE_DRIVER` / `SESSION_DRIVER` | `redis` / `redis` |
| `REDIS_HOST` `REDIS_PORT` `REDIS_PASSWORD` | 实际 Redis |
| `REVERB_APP_ID` `REVERB_APP_KEY` `REVERB_APP_SECRET` | 与前端 `VITE_REVERB_*` 一致 |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | `botwss.dg178.top` / `443` / `https` |
| `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` | `botmg.dg178.top` / `.dg178.top` |
| `EXTERNAL_API_KEY` | 与前端 `VITE_API_KEY` 一致 |
| `MAIL_*` / `AWS_*` / `ALIOSS_*` / `OPENAI_*` | 按需填写 |

---

## 5. 前端构建

`VITE_*` 在**构建时**注入，改了必须重新 build。

```bash
cd web
cp .env.example .env.production
```

`web/.env.production`：

| 变量 | 生产取值 |
|---|---|
| `VITE_BASE_URL` | `https://bot.dg178.top/api` |
| `VITE_APP_NAME` | 后台名称 |
| `VITE_REVERB_APP_KEY` | 与后端 `REVERB_APP_KEY` 一致 |
| `VITE_REVERB_HOST` / `VITE_REVERB_PORT` / `VITE_REVERB_SCHEME` | `botwss.dg178.top` / `443` / `https` |
| `VITE_WSS_URL` | `wss://botwss.dg178.top/app` |
| `VITE_API_KEY` | 与后端 `EXTERNAL_API_KEY` 一致 |

```bash
yarn install
yarn build          # = vite build --mode production，产物 web/dist
```

---

## 6. Nginx

单 server 块托管 SPA（`/`）+ API（`/api`）+ Reverb 反代（`/app`）。

```nginx
server {
    listen 80;
    server_name bot.dg178.top;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name bot.dg178.top;

    ssl_certificate     /etc/nginx/ssl/bot.dg178.top/fullchain.pem;
    ssl_certificate_key /etc/nginx/ssl/bot.dg178.top/privkey.pem;

    root /var/www/telegram-bot/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    client_max_body_size 50m;

    location /api {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass   unix:/run/php/php8.2-fpm.sock;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include        fastcgi_params;
    }

    location /app {
        proxy_pass             http://127.0.0.1:8080;
        proxy_http_version     1.1;
        proxy_set_header       Upgrade $http_upgrade;
        proxy_set_header       Connection "Upgrade";
        proxy_set_header       Host $host;
        proxy_set_header       X-Real-IP $remote_addr;
        proxy_set_header       X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header       X-Forwarded-Proto $scheme;
        proxy_read_timeout     3600s;
        proxy_send_timeout     3600s;
    }

    location / {
        root /var/www/telegram-bot/web/dist;
        try_files $uri $uri/ /index.html;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?|ttf|eot)$ {
        root /var/www/telegram-bot/web/dist;
        expires 7d;
        add_header Cache-Control "public";
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## 7. 常驻进程（Supervisor）

`/etc/supervisor/conf.d/telegram-bot.conf`：

```ini
[program:reverb]
command=php /var/www/telegram-bot/artisan reverb:start --host=0.0.0.0 --port=8080
directory=/var/www/telegram-bot
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/telegram-bot/storage/logs/reverb.log
stopwaitsecs=10

[program:queue]
command=php /var/www/telegram-bot/artisan queue:work redis --queue=default --tries=3 --max-time=3600
directory=/var/www/telegram-bot
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/telegram-bot/storage/logs/queue.log
numprocs=2
process_name=%(program_name)s_%(process_num)02d

[program:telegram-listener]
command=php /var/www/telegram-bot/artisan telegram:multi-listen
directory=/var/www/telegram-bot
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/telegram-bot/storage/logs/telegram-listener.log
stopwaitsecs=15
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
sudo supervisorctl status
```

`telegram:scan-activity` 是一次性命令，不要放进 Supervisor。

---

## 8. 定时任务

```cron
* * * * * cd /var/www/telegram-bot && php artisan schedule:run >> /dev/null 2>&1
```

---

## 9. 升级

```bash
cd /var/www/telegram-bot
git pull origin main
composer install --no-dev --optimize-autoloader

php artisan migrate
php artisan catch:migrate user
php artisan catch:migrate develop
php artisan catch:migrate cms
php artisan catch:migrate system
php artisan catch:migrate telegram
php artisan telegram:scan-activity

php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache

cd web && yarn install && yarn build

sudo supervisorctl restart all
sudo systemctl reload nginx
```

回滚：`git checkout` 上一提交 + 恢复数据库备份 + 重新 `composer install` / `yarn build` + 重启 Supervisor。

---

## 10. 上线检查清单

- [ ] PHP 8.2 扩展齐全、`tesseract-ocr` 已装
- [ ] `.env` 已配：`APP_DEBUG=false`、`BROADCAST_DRIVER=reverb`、`QUEUE_CONNECTION=redis`、`REVERB_*` 与前端一致
- [ ] `catch:install` 已跑，初始账号已改密码
- [ ] `cms` / `system` / `telegram` 三个模块已单独安装，`storage/app/modules.json` 含全部模块
- [ ] `telegram:scan-activity` 已执行
- [ ] `storage:link` 已建，`storage` / `bootstrap/cache` 属主为 `www-data`
- [ ] 前端 `web/.env.production` 指向生产域名，已 `yarn build`
- [ ] `nginx -t` 通过并已 reload
- [ ] Supervisor 三个进程 RUNNING
- [ ] 防火墙只开 80/443；8080 / 9000 / 3306 / 6379 仅内网
