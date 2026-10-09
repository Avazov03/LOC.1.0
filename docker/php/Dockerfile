FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev libicu-dev unzip git \
    && docker-php-ext-install pdo_pgsql pgsql opcache pcntl zip intl bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf

# Set to 0 in production images where the code does not change at runtime.
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
# Each FPM worker can use up to memory_limit (256M); size this to the container's memory.
ENV PHP_FPM_MAX_CHILDREN=20

WORKDIR /var/www/html
