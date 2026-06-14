#!/bin/sh
set +e

if [ -z "$APP_KEY" ]; then
  echo "[scheduler] ERROR: APP_KEY não configurada."
  exit 1
fi

if [ -z "$DATABASE_URL" ] && [ "$DB_CONNECTION" != "pgsql" ]; then
  echo "[scheduler] ERROR: Postgres não configurado."
  exit 1
fi

php artisan schedule:run --no-interaction
exit $?
