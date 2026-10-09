#!/usr/bin/env bash
# Daily PostgreSQL backup on the production server (docs/DEPLOYMENT.md §7).
# Writes a custom-format pg_dump, checks that pg_restore can read it, then deletes dumps older than BACKUP_KEEP_DAYS.
# The dumps live outside the project directory so they never reach a Docker build context or Git.
set -euo pipefail
cd "$(dirname "$0")/.."

dir="${BACKUP_DIR:-$HOME/loc-backups}"
keep="${BACKUP_KEEP_DAYS:-14}"
dc() { docker compose -f docker-compose.yml -f docker-compose.prod.yml "$@"; }

umask 077
mkdir -p "$dir"
file="$dir/loc-$(date -u +%Y%m%d-%H%M%S).dump"
trap 'rm -f "$file.part"' EXIT

dc exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$file.part"
dc exec -T postgres pg_restore --list < "$file.part" > /dev/null
mv "$file.part" "$file"

find "$dir" -maxdepth 1 -name 'loc-*.dump' -mtime +"$keep" -delete
echo "Backup OK: $file ($(du -h "$file" | cut -f1))"
