# 1️⃣ Base image
FROM php:8.3-cli

# 2️⃣ Install system dependencies
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    curl \
    libzip-dev \
    libonig-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    nodejs \
    npm \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql zip gd mbstring

# 3️⃣ Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4️⃣ Set working directory
WORKDIR /app
COPY . /app

# 5️⃣ Install PHP dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# 6️⃣ Install Node dependencies & build frontend
RUN npm install
RUN npm run build

# 7️⃣ Expose port 10000
EXPOSE 10000

# 8️⃣ Start Laravel server
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=10000"]
