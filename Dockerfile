# Use latest PHP CLI (8.3)
FROM php:8.3-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    unzip git curl libzip-dev nodejs npm

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app
COPY . /app

# Install PHP dependencies
RUN composer install

# Install Node dependencies & build Vite frontend (optional)
RUN npm install
RUN npm run build

# Expose port
EXPOSE 10000

# Start Laravel server
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=10000"]
