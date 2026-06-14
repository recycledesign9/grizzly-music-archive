FROM php:8.1-apache

RUN apt-get update && apt-get install -y \
        libpng-dev \
        libjpeg-dev \
        libwebp-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install pdo pdo_mysql gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

RUN printf "upload_max_filesize=512M\npost_max_size=512M\nmemory_limit=256M\nmax_execution_time=300\nmax_input_time=300\n" \
    > /usr/local/etc/php/conf.d/grizzly-uploads.ini

COPY . /var/www/html/

COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

RUN mkdir -p \
        /var/www/html/public/uploads/covers \
        /var/www/html/public/uploads/audio \
    && chown -R www-data:www-data /var/www/html/public/uploads \
    && chmod -R 775 /var/www/html/public/uploads

EXPOSE 80
