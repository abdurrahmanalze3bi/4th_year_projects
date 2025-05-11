# 1) Build front-end assets with Node
FROM node:20 AS frontend-builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# 2) Install PHP dependencies with Composer (no scripts yet)
FROM composer:2 AS composer-builder
WORKDIR /app
COPY composer.json composer.lock ./

# Install PHP deps without running any scripts
RUN composer install --no-dev --optimize-autoloader --no-scripts

# 2.1) Copy in the rest of your application so artisan can discover packages
COPY . .

# 2.2) Manually run the post-autoload scripts (package discovery, etc.)
RUN composer run-script post-autoload-dump

# 3) Final image: PHP + Apache + your built code
FROM php:8.3-apache

# Enable rewrite and point DocumentRoot at public/
RUN a2enmod rewrite \
 && sed -ri 's!DocumentRoot /var/www/html!DocumentRoot /var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's!<Directory /var/www/html>!<Directory /var/www/html/public>!g' /etc/apache2/apache2.conf

# Install system libs + PHP extensions
RUN apt-get update \
 && apt-get install -y libpng-dev libonig-dev libxml2-dev zip unzip git \
 && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

WORKDIR /var/www/html

# Copy in vendor and built front-end assets
COPY --from=composer-builder /app/vendor ./vendor
COPY --from=frontend-builder /app/public/build ./public/build

# Copy the rest of your application
COPY . .

# Fix permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
CMD ["apache2-foreground"]
