# vendor/composer/platform_check.php 要求 >= 8.4.1（composer.lock 是按 8.5 装的）
FROM php:8.4-fpm

# 系统依赖
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        zip \
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

# Redis 扩展（生产 Ubuntu 也是 phpredis，保持客户端一致）
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

CMD ["php-fpm"]
