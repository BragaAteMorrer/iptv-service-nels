FROM php:8.3-cli-alpine
RUN apk add --no-cache postgresql-dev git unzip \
 && docker-php-ext-install pdo_pgsql
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader --no-scripts
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
