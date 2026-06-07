FROM php:8.1-apache

# ── System dependencies ────────────────────────────────────────────────────────
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

# ── Apache: mod_rewrite ────────────────────────────────────────────────────────
RUN a2enmod rewrite

# ── PHP limits for audio upload (up to 512 MB) ────────────────────────────────
RUN printf \
    "upload_max_filesize=512M\npost_max_size=512M\nmemory_limit=256M\nmax_execution_time=300\nmax_input_time=300\n" \
    > /usr/local/etc/php/conf.d/grizzly-uploads.ini

# ── Copy application source ────────────────────────────────────────────────────
COPY . /var/www/html/

# ── Upload directories with correct ownership ─────────────────────────────────
RUN mkdir -p \
        /var/www/html/public/uploads/covers \
        /var/www/html/public/uploads/audio \
    && chown -R www-data:www-data /var/www/html/public/uploads \
    && chmod -R 775 /var/www/html/public/uploads

# ── Apache: point DocumentRoot at /public ─────────────────────────────────────
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

# ── Apache: allow .htaccess overrides ─────────────────────────────────────────
RUN sed -i 's/AllowOverride None/AllowOverride All/g' \
        /etc/apache2/apache2.conf

EXPOSE 80
