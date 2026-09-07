#!/bin/sh
set -eu

cd /var/www

# Boot-time priming: copy a fresh .env.example when no .env exists, generate an
# APP_KEY when absent, and refresh the package service-provider discovery cache.
# Environment variables passed by Docker Compose always override .env values,
# so DB_HOST/services etc. come from the compose configuration.

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -Eq '^APP_KEY=.+' .env; then
    php artisan key:generate --force
fi

php artisan package:discover --ansi >/dev/null 2>&1 || true

exec "$@"