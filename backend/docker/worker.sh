#!/bin/sh

log() {
  echo "[worker] $1 $(date -u +%Y-%m-%dT%H:%M:%SZ)"
}

on_exit() {
  code=$?
  if [ -n "$SCHEDULER_PID" ]; then
    kill "$SCHEDULER_PID" 2>/dev/null || true
  fi
  log "encerrando (exit ${code})"
}

trap on_exit INT TERM EXIT

if [ -z "$APP_KEY" ]; then
  log "ERROR: APP_KEY não configurada no Render (partiumenu-worker → Environment)."
  exit 1
fi

if [ -z "$DATABASE_URL" ] && [ "$DB_CONNECTION" != "pgsql" ]; then
  log "ERROR: Postgres não configurado no worker."
  log "No Render: link partiumenu_prod ao worker OU copie DATABASE_URL e DB_CONNECTION=pgsql da API."
  exit 1
fi

php artisan optimize:clear || log "WARN: optimize:clear falhou"
php artisan config:cache || log "WARN: config:cache falhou"

log "online — queue + scheduler"

(
  while true; do
    php artisan schedule:run --no-interaction --verbose
    sleep 60
  done
) &

SCHEDULER_PID=$!

while true; do
  log "queue:work iniciando"
  php artisan queue:work \
    --sleep=3 \
    --tries=3 \
    --timeout=90 \
    --memory=256 \
    --max-jobs=200
  code=$?
  log "queue:work saiu com código ${code}; reiniciando em 3s"
  sleep 3
done
