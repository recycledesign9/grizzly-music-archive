FROM php:8.3.36-apache

RUN apt-get update && apt-get install -y \
        libpng-dev \
        libjpeg-dev \
        libwebp-dev \
        libfreetype6-dev \
        libzip-dev \
        libonig-dev \
        libcurl4-openssl-dev \
        unzip \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install pdo pdo_mysql gd zip mbstring curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

RUN printf "upload_max_filesize=512M\npost_max_size=512M\nmemory_limit=256M\nmax_execution_time=300\nmax_input_time=300\n" \
    > /usr/local/etc/php/conf.d/grizzly-uploads.ini

# Disable PHP session upload-progress state (security hardening).
RUN printf 'session.upload_progress.enabled=0\n' \
    > /usr/local/etc/php/conf.d/grizzly-security.ini

COPY . /var/www/html/

COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

# Normalize read/traverse access for application code and static assets.
# Docker COPY preserves source permission bits; these may be restrictive when
# the project was transferred with rsync -a or extracted from an archive.
# Do not change ownership or permissions of uploads, storage or .env here.
RUN set -eu; \
    chmod a+rx /var/www/html /var/www/html/public; \
    for path in \
        /var/www/html/app \
        /var/www/html/views \
        /var/www/html/config \
        /var/www/html/api \
        /var/www/html/public/css \
        /var/www/html/public/js \
        /var/www/html/public/fonts \
        /var/www/html/public/img \
        /var/www/html/index.php \
        /var/www/html/media-scan-worker.php \
        /var/www/html/VERSION \
        /var/www/html/.htaccess; do \
        if [ -e "$path" ]; then chmod -R a+rX "$path"; fi; \
    done

RUN mkdir -p \
        /var/www/html/public/uploads/covers \
        /var/www/html/public/uploads/audio \
        /var/www/html/storage/logs \
        /var/www/html/cache \
        /var/lib/grizzly-worker \
    && chown -R www-data:www-data \
        /var/www/html/public/uploads \
        /var/www/html/storage \
        /var/www/html/cache \
        /var/lib/grizzly-worker \
    && chmod -R 775 \
        /var/www/html/public/uploads \
        /var/www/html/storage \
        /var/www/html/cache \
        /var/lib/grizzly-worker

EXPOSE 80
