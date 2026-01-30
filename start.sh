#!/bin/bash

# Start PHP-FPM
php-fpm -D

# Run migrations (optional)
php artisan migrate --force

# Cache config
php artisan config:cache
php artisan route:cache

# Start Nginx
nginx -g 'daemon off;'
```
