FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction \
    --no-scripts

FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS libssl-dev libzstd-dev libbsd-dev libbsd0 pkg-config ca-certificates \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS libssl-dev libzstd-dev libbsd-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN addgroup --system app \
    && adduser --system --ingroup app app \
    && chown -R app:app /var/www/html

USER app

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV PORT=8000
ENV LD_PRELOAD=/usr/lib/x86_64-linux-gnu/libbsd.so.0

EXPOSE 8000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8000} -t public"]
