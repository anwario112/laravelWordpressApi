# 1️⃣ Start from PHP 8.3 CLI with Debian (apt-get available)
FROM php:8.3-cli-bullseye

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
    libxml2-dev \
    zlib1g-dev \
    nodejs \
    npm \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql zip gd mbstring bcmath xml ctype tokenizer \
    && rm -rf /var/lib/apt/lists/*

# 3️⃣ Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# 4️⃣ Set working directory
WORKDIR /app
COPY . /app

# 5️⃣ Install PHP dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# 6️⃣ Install Node dependencies & build Vite frontend
RUN npm install
RUN npm run build

# 7️⃣ Expose port
EXPOSE 10000

# 8️⃣ Start Laravel server
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=10000"]
