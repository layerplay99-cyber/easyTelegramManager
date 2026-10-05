# 公共 PHP 基础镜像：php:8.4-fpm + 常用扩展 + composer
# 构建后打 tag stack-php:8.4，供所有项目复用，避免每个项目各自 build。
FROM php:8.4-fpm

# 系统依赖
RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl wget \
        unzip zip \
        default-mysql-client \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libicu-dev \
        libxml2-dev \
        libonig-dev \
        libcurl4-openssl-dev \
        libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP 扩展
# pcntl / sockets / sysvsem：队列 worker 与 MadelineProto 需要
# pdo_mysql / mysqli：MySQL 驱动
# gd / intl / bcmath / exif / zip / opcache：业务常用
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mysqli \
        zip \
        gd \
        intl \
        bcmath \
        exif \
        pcntl \
        sockets \
        sysvsem \
        opcache

# Redis 扩展（与主流生产一致，phpredis 客户端）
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /opt/projects

CMD ["php-fpm"]
