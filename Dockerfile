# 1) Build front-end assets with Node
FROM node:20 AS frontend-builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# 2) Install PHP dependencies with Composer
FROM composer:2 AS composer-builder
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

# 3) Final image: PHP + Apache + your built code
FROM php:8.3-apache
# Install system libs + PHP extensions
RUN apt-get update \
 && apt-get install -y libpng-dev libonig-dev libxml2-dev zip unzip git \
 && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Copy PHP deps and front-end build into webroot
WORKDIR /var/www/html
COPY --from=composer-builder /app/vendor ./vendor
COPY --from=frontend-builder /app/public/build ./public/build
COPY . .

# Generate app key & fix permissions
RUN php artisan key:generate \
 && chown -R www-data:www-data /var/www/html

# Expose HTTP port and launch Apache
EXPOSE 80
CMD ["apache2-foreground"]
