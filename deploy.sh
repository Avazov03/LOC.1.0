#!/usr/bin/env bash
# Production deploy. Run on the server from the project directory after `git pull --ff-only`.
# Requires a filled-in .env (see docs/DEPLOYMENT.md). Safe to re-run.
set -euo pipefail
cd "$(dirname "$0")"

dc() { docker compose -f docker-compose.yml -f docker-compose.prod.yml "$@"; }
artisan() { dc run --rm -T -u www-data app php artisan "$@"; }

echo "==> Building images"
dc build --pull

echo "==> Building frontend assets"
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app node:22-alpine \
    sh -c "npm ci --no-audit --no-fund && npm run build"

echo "==> Starting Postgres and Redis"
dc up -d --wait postgres redis

echo "==> Installing PHP dependencies"
dc run --rm -T app composer install --no-dev --optimize-autoloader --no-interaction --no-progress
dc run --rm -T --no-deps app chown -R www-data:www-data storage bootstrap/cache

echo "==> Migrating and caching"
artisan migrate --force
artisan optimize

echo "==> Restarting services"
dc up -d --remove-orphans
dc restart app queue scheduler

echo "==> Health check"
for i in $(seq 1 20); do
    if curl -fsS http://127.0.0.1:8080/health; then echo; echo "Deploy OK"; exit 0; fi
    sleep 3
done
echo "Health check failed" >&2
dc ps
exit 1
