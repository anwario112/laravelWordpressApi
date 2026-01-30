FROM composer:2.7 AS build

WORKDIR /app
COPY . /app

RUN apt-get update && apt-get install -y \
    unzip git nodejs npm libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql zip gd mbstring bcmath xml ctype tokenizer

RUN composer install --no-interaction --prefer-dist --optimize-autoloader
RUN npm install && npm run build

EXPOSE 10000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=10000"]
