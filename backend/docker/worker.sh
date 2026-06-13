#!/bin/sh

if [ -z "$APP_KEY" ]; then
  echo "ERROR: APP_KEY não configurada no Render (partiumenu-worker → Environment)."
  exit 1
fi

if [ -z "$DATABASE_URL" ] && [ "$DB_CONNECTION" != "pgsql" ]; then
  echo "ERROR: Postgres não configurado no worker."
  echo "No Render: link partiumenu_prod ao worker OU copie DATABASE_URL e DB_CONNECTION=pgsql da API."
  exit 1
fi

php artisan optimize:clear
php artisan config:cache

echo "Worker iniciado: queue + schedule"

(
  while true; do
    php artisan schedule:work
    echo "schedule:work saiu; reiniciando em 3s..."
    sleep 3
  done
) &

while true; do
  php artisan queue:work --sleep=3 --tries=3
  echo "queue:work saiu; reiniciando em 3s..."
  sleep 3
done
