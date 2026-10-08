#!/bin/sh
# Runs once, on a fresh volume, after the postgis image created the main database.
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -f /docker-entrypoint-initdb.d/app/01-test-database.sql
