FROM webdevops/php:8.2-alpine

# 安裝必要的 PHP 擴充與依賴
RUN apk add --no-cache \
    autoconf \
    build-base \
    linux-headers \
    libevent-dev \
    openldap-dev \
    imagemagick-dev \
    openssl-dev \
    && docker-php-ext-install opcache bcmath sockets \
    && if php -m | grep -qi '^redis$'; then echo 'redis already installed'; else pecl install redis && docker-php-ext-enable redis; fi

# 安裝 Event 擴充 (Workerman 需要)
RUN pecl install event \
    && docker-php-ext-enable --ini-name zz-event.ini event

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 複製設定檔 (請確保這三個檔案也在 event-gateway 資料夾內)
# 將設定檔放到擴充目錄，副檔名必須是 .conf
COPY supervisord.conf /opt/docker/etc/supervisor.d/consumer.conf
COPY supervisord.recalc.conf /etc/supervisor/conf.d/recalc.conf
COPY supervisord.monitor.conf /etc/supervisor/conf.d/monitor.conf

# 複製專案程式碼
COPY . /app

# 預設啟動指令
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
