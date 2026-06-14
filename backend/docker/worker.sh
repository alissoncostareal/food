#!/bin/sh
set +e

log() {
  echo "[worker] $1 $(date -u +%Y-%m-%dT%H:%M:%SZ)"
}

on_signal() {
  log "sinal recebido ($1), encerrando scheduler pid=${SCHEDULER_PID:-?}"
  if [ -n "$SCHEDULER_PID" ]; then
    kill "$SCHEDULER_PID" 2>/dev/null || true
  fi
  exit 0
}

trap 'on_signal TERM' TERM
trap 'on_signal INT' INT

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

log "online pid=$$ — queue (128M, max 15min) + scheduler (96M, 1/min)"

(
  tick=0
  while true; do
    tick=$((tick + 1))
    php -d memory_limit=96M artisan schedule:run --no-interaction
    schedule_code=$?
    if [ "$schedule_code" -ne 0 ]; then
      log "WARN: schedule:run exit=${schedule_code}"
    elif [ $((tick % 10)) -eq 0 ]; then
      log "heartbeat scheduler ok (tick=${tick})"
    fi
    sleep 60
  done
) &

SCHEDULER_PID=$!

while true; do
  log "queue:work iniciando"
  php -d memory_limit=128M artisan queue:work \
    --sleep=3 \
    --tries=3 \
    --timeout=90 \
    --memory=96 \
    --max-jobs=50 \
    --max-time=900
  code=$?
  log "queue:work saiu com código ${code}; reiniciando em 5s"
  sleep 5
done
