# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# MasterInTech backend (Laravel API) - development image
#
# php:8.3-cli + required extensions + FFmpeg/FFprobe (for the local HLS video
# pipeline) + ffprobe. The app runs via `php artisan serve` for dev; jobs run
# via a separate `worker` service (queue:work).
#
# No production credentials are embedded: secrets come from the environment.
# ---------------------------------------------------------------------------

FROM composer:2 AS build

WORKDIR /app

# Install backend dependencies. `--no-scripts` avoids running artisan at build
# time (the entrypoint runs `php artisan package:discover` on every container
# start instead).
COPY backend/ /app/

RUN composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader --no-scripts

FROM php:8.3-cli-alpine AS runtime

# FFmpeg/FFprobe are required by the video transcode/remux pipeline
# (VIDEO_SECURITY_DRIVER=local_hls). PHP extensions match composer/platform
# requirements and enable MySQL + SQLite for dev parity.
RUN apk add --no-cache \
        curl unzip git \
        icu-dev oniguruma-dev libzip-dev \
        freetype-dev libpng-dev libjpeg-turbo-dev \
        ffmpeg \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        ctype \
        exif \
        fileinfo \
        gd \
        intl \
        mbstring \
        pdo \
        pdo_mysql \
        pdo_sqlite \
        zip

# Keep a working composer binary (used to refresh autoload/discovery if needed).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY --from=build /app /var/www

COPY deploy/backend/entrypoint.sh /usr/local/bin/docker-entrypoint

RUN chmod +x /usr/local/bin/docker-entrypoint \
    && mkdir -p \
        /var/www/storage/framework/cache/data \
        /var/www/storage/framework/sessions \
        /var/www/storage/framework/views \
        /var/www/storage/logs \
        /var/www/bootstrap/cache \
    && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

ENV APP_ENV=local \
    APP_DEBUG=true

EXPOSE 8001

ENTRYPOINT ["/usr/local/bin/docker-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8001"]