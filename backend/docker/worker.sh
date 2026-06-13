#!/bin/sh
set -e

php artisan optimize:clear
php artisan config:cache

php artisan queue:work --sleep=3 --tries=3 &
php artisan schedule:work &
wait
