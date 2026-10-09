# Deployment

One Laravel monolith in Docker: `app` (PHP-FPM 8.4), `nginx`, `postgres` (PostGIS 17-3.5), `redis`, `queue`, `scheduler`. The same image runs the web app, the queue worker and the scheduler.

## 1. Before the first start

1. Copy `.env.example` to `.env` on the server. `.env` is gitignored and is the only place secrets live.
2. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<your domain>`, then `php artisan key:generate`.
3. Change the database password. `docker-compose.yml` uses `internship/internship` for local development. In production put a strong `DB_PASSWORD` in `.env`; `docker-compose.prod.yml` passes it to Postgres and the PHP containers.
4. `docker-compose.prod.yml` removes the host ports of `postgres` and `redis`, publishes nginx only on `127.0.0.1:8080`, turns off OPcache timestamp checks, caps FPM at 8 workers and sets memory limits per container.
5. Telegram: create the bot with BotFather and put its token in `TELEGRAM_BOT_TOKEN`. Set `TELEGRAM_BOT_USERNAME` (public, used in invite links) and a random `TELEGRAM_WEBHOOK_SECRET` of at least 16 characters, for example `openssl rand -hex 32`. Never commit these values or paste them into documents or chats. If a token was ever shared, rotate it in BotFather (`/revoke`) and update `.env`.
6. First admin: set `ADMIN_LOGIN`, `ADMIN_PASSWORD` (and optionally `ADMIN_NAME`, `ADMIN_EMAIL`, `UNIVERSITY_NAME`, `UNIVERSITY_TIMEZONE`). Remove `ADMIN_PASSWORD` from `.env` after the first run if you prefer.

## 2. Start

In production run `./deploy.sh`. It builds the images and the frontend (in a `node:22-alpine` container), installs Composer dependencies without dev packages, migrates, caches config/routes/views, restarts the app, queue and scheduler, and checks `/health`. It is also the update command: `git pull --ff-only && ./deploy.sh`. Run `php artisan admin:ensure` and `php artisan telegram:webhook` once after the first deploy:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm -u www-data app php artisan admin:ensure
```

The manual steps, for development or a different setup:

```bash
docker compose build
docker compose up -d
docker compose exec app composer install --no-dev --optimize-autoloader
docker compose exec app php artisan migrate --force
docker compose exec app php artisan admin:ensure
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
npm ci && npm run build        # or build assets in CI and ship public/build
```

Do not run `db:seed` in production: the seeder creates fictional demo data and demo passwords.

`admin:ensure` creates the first ADMIN from `ADMIN_*`, or refreshes its password and status if it exists. It refuses an empty login or a short password.

In production set `PHP_OPCACHE_VALIDATE_TIMESTAMPS=0` for the `app`, `queue` and `scheduler` services and restart them after each deploy (`docker compose restart app queue scheduler`).

## 3. HTTPS

Telegram only delivers webhooks to HTTPS. The nginx container listens on port 80; terminate TLS in front of it:

- a reverse proxy or load balancer with a certificate (Caddy, Traefik, a cloud load balancer, or a host nginx with Let's Encrypt), forwarding to `nginx:80`; or
- add a `listen 443 ssl` server to `docker/nginx/default.conf` with mounted certificates and redirect port 80 to 443.

The production server uses a host nginx with a Let's Encrypt certificate (certbot) that proxies to `127.0.0.1:8080` and sends `X-Forwarded-For` and `X-Forwarded-Proto`. Set `TRUSTED_PROXIES=172.16.0.0/12` (the Docker bridge networks) and `SESSION_SECURE_COOKIE=true`.

Set `APP_URL` to the HTTPS address. Behind a proxy, configure trusted proxies so Laravel sees the HTTPS scheme and the client IP (the webhook and login rate limits key on the IP).

## 4. Telegram webhook

```bash
docker compose exec app php artisan telegram:webhook            # uses APP_URL/telegram/webhook
docker compose exec app php artisan telegram:webhook --info     # bot name, URL, pending updates, last error
docker compose exec app php artisan telegram:webhook --delete
```

The command refuses a non-HTTPS URL and a secret shorter than 16 characters. Telegram sends the secret in `X-Telegram-Bot-Api-Secret-Token`; a wrong value is refused with 403 and limited to 120 requests per minute per IP. Each `update_id` is processed once.

`php artisan telegram:poll` exists for local development only (no public HTTPS). It deletes the webhook while it runs; set the webhook again before going live.

## 5. Workers and scheduler

- `queue`: `php artisan queue:work redis --tries=3 --backoff=10 --timeout=620 --max-time=3600`. It sends Telegram notifications and builds CSV exports. `REDIS_QUEUE_RETRY_AFTER` (default 660) must stay above `--timeout`.
- `scheduler`: `php artisan schedule:work`. Jobs: `invites:expire` and `assignments:activate-due` every 5 minutes, `attendance:close-stale` every 15 minutes, `telegram:prune` daily at 03:30, `queue:prune-failed` daily.
- Check-in and check-out are never queued; the bot answers inside the webhook request.

## 6. Health

`GET /health` returns `{"status":"ok","checks":{"database":"ok","cache":"ok"}}` or HTTP 503 with the failing check. The nginx container's health check calls it; point an external monitor at it as well.

## 7. Backups

Attendance lives only in PostgreSQL. Redis holds cache, queue and rate limits and can be rebuilt.

```bash
# backup (custom format, includes PostGIS data)
docker compose exec -T postgres pg_dump -U internship -Fc -d internship > backup-$(date +%F).dump

# restore into an empty database
docker compose exec -T postgres createdb -U internship internship_restore
docker compose exec -T postgres pg_restore -U internship -d internship_restore --no-owner < backup-YYYY-MM-DD.dump
```

Automatic daily dump: `scripts/backup.sh` writes `~/loc-backups/loc-YYYYMMDD-HHMMSS.dump` (outside the project directory, mode 600), checks it with `pg_restore --list` and deletes dumps older than 14 days (`BACKUP_DIR`, `BACKUP_KEEP_DAYS` override). Install it once in the deploy user's crontab:

```bash
chmod +x /opt/loc/scripts/backup.sh && mkdir -p -m 700 ~/loc-backups
( crontab -l 2>/dev/null | grep -v loc-backup; echo '30 2 * * * /opt/loc/scripts/backup.sh >> $HOME/loc-backups/backup.log 2>&1 # loc-backup' ) | crontab -
```

These dumps protect against a bad migration or a deleted row, not against losing the server. Also turn on Lightsail automatic snapshots (instance → Snapshots → Automatic snapshots), and keep at least weekly dumps off the server, plus `storage/app` if you need finished CSV files. A restore drill was run on 8 October 2026: dump of the demo database, restore into a new database, identical row counts for users, events, sessions, audit rows, organizations and migrations, PostGIS present, both immutability triggers present.

## 8. Logs and updates

Application logs go to `storage/logs` (set `LOG_CHANNEL=daily` or `stderr` for container logging). Logs never contain the bot token. To update: `git pull --ff-only && ./deploy.sh`.
