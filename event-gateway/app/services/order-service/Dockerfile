FROM php:8.2-cli-alpine

# 安裝基礎套件與擴充
RUN apk add --no-cache \
    git \
    unzip \
    zlib-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    postgresql-dev \
    linux-headers \
    supervisor

# 安裝 PHP 擴充
RUN docker-php-ext-configure zip \
    && docker-php-ext-install \
    pdo_mysql \
    pdo_pgsql \
    zip \
    intl \
    opcache \
    bcmath \
    sockets

# 安裝 Redis 擴充
RUN apk add --no-cache pcre-dev $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 複製程式碼
COPY . /app

# 如果您有 supervisord.conf，請解開下面這行；如果沒有，可以使用簡單的 CMD 啟動
# COPY supervisord.conf /etc/supervisord.conf
# CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]

# 開發階段暫時用這個指令保持容器啟動 (方便除錯)
CMD ["tail", "-f", "/dev/null"]
