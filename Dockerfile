FROM php:8.4-cli-alpine
RUN apk add --no-cache postgresql-dev libxml2-dev git unzip \
 && docker-php-ext-install dom \
 && docker-php-ext-install xmlreader simplexml pdo_pgsql
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
RUN printf 'upload_max_filesize=350M\npost_max_size=360M\nmemory_limit=512M\nmax_execution_time=300\n' > /usr/local/etc/php/conf.d/iptv-upload.ini
COPY . .
RUN composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader --no-scripts
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/router.php"]
