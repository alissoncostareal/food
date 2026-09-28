#!/bin/sh
set -e

mkdir -p \
  /var/www/html/storage/framework/views \
  /var/www/html/storage/framework/cache/data \
  /var/www/html/storage/framework/sessions \
  /var/www/html/storage/logs \
  /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

php artisan optimize:clear
php artisan migrate --force
php artisan storage:link || true
php artisan app:ensure-super-admin || true
php artisan config:cache
php artisan route:cache

exec apache2-foreground
