#!/usr/bin/env bash
# mojavez-v3 — Docker entrypoint.
# Waits for MySQL to be reachable, then runs migrations (if INIT_DB=1).
# Per docs/00-MASTER.md §16.2.
set -e

MAX_ATTEMPTS=60
ATTEMPT=0

echo "[mojavez-entrypoint] Waiting for MySQL at ${DB_HOST:-db}:${DB_PORT:-3306}..."
until mysqladmin ping -h "${DB_HOST:-db}" -P "${DB_PORT:-3306}" \
        -u "${DB_USER:-mojavez}" -p"${DB_PASSWORD:-mojavez_pass}" --silent 2>/dev/null; do
    ATTEMPT=$((ATTEMPT+1))
    if [ "$ATTEMPT" -ge "$MAX_ATTEMPTS" ]; then
        echo "[mojavez-entrypoint] MySQL not reachable after $MAX_ATTEMPTS attempts — giving up."
        exit 1
    fi
    sleep 2
done
echo "[mojavez-entrypoint] MySQL is up (after $ATTEMPT attempts)."

if [ "${INIT_DB:-1}" = "1" ]; then
    echo "[mojavez-entrypoint] Running migrations..."
    php /var/www/html/scripts/migrate.php --all
    echo "[mojavez-entrypoint] Migrations done."
fi

echo "[mojavez-entrypoint] Starting: $@"
exec "$@"
