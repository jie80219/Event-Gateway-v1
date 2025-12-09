# 使用輕量級 PHP 8.2 CLI Alpine 版本
FROM php:8.2-cli-alpine

# 安裝基礎依賴與 Redis 擴充
RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    autoconf \
    build-base \
    linux-headers \
    && docker-php-ext-install \
    pdo_mysql \
    zip \
    intl \
    opcache \
    bcmath \
    sockets \
    && pecl install redis \
    && docker-php-ext-enable redis

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 設定工作目錄
WORKDIR /app

# 複製專案程式碼
COPY . /app

# 預設指令 (保持容器運行，方便開發)
CMD ["tail", "-f", "/dev/null"]