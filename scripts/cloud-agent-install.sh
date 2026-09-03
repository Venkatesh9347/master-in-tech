#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT/backend"

if [[ ! -f .env ]]; then
  cp .env.example .env
fi

composer install --no-interaction --prefer-dist

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

touch database/database.sqlite
php artisan migrate --force

cd "$ROOT/frontend"
npm ci
