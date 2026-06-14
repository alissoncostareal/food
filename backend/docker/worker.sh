#!/bin/sh
set +e

log() {
  echo "[worker] $1 $(date -u +%Y-%m-%dT%H:%M:%SZ)"
}

if [ -z "$APP_KEY" ]; then
  log "ERROR: APP_KEY não configurada (partiumenu-worker → Environment)."
  exit 1
fi

if [ -z "$DATABASE_URL" ] && [ "$DB_CONNECTION" != "pgsql" ]; then
  log "ERROR: Postgres não configurado no worker."
  exit 1
fi

php artisan optimize:clear || log "WARN: optimize:clear falhou"
php artisan config:cache || log "WARN: config:cache falhou"

log "online pid=$$ — somente fila (scheduler = Cron Job partiumenu-scheduler)"

while true; do
  log "queue:work iniciando"
  php -d memory_limit=256M artisan queue:work \
    --sleep=3 \
    --tries=3 \
    --timeout=90 \
    --memory=192 \
    --max-jobs=100 \
    --max-time=3600
  code=$?
  log "queue:work saiu com código ${code}; reiniciando em 3s"
  sleep 3
done
