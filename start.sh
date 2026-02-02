#!/bin/bash

# Clear all caches first (important!)
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Start PHP-FPM
php-fpm -D

# Run migrations (optional)
php artisan migrate --force

# DO NOT cache config on Render - it will use old .env values
# Only cache routes (safe to cache)
php artisan route:cache

# Start Nginx
nginx -g 'daemon off;'
