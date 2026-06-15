#!/bin/sh
set -e

php artisan optimize:clear
php artisan migrate --force
php artisan storage:link || true
php artisan app:ensure-super-admin || true
php artisan config:cache
php artisan route:cache

exec apache2-foreground
