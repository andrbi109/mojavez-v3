# mojavez-v3 — PHP-FPM + Apache image.
# Per docs/00-MASTER.md §16.2.
FROM php:8.2-apache

# System deps + PHP extensions needed by mojavez-v3:
#   pdo_mysql, mbstring, fileinfo, gd (image re-encode), curl (MojavecClient).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libzip-dev unzip curl default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring fileinfo gd curl zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# DocumentRoot → public/ (so .env, app/, scripts/ stay outside web root).
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf
RUN echo "<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>" \
    > /etc/apache2/conf-available/mojavez-docroot.conf \
    && a2enconf mojavez-docroot

WORKDIR /var/www/html

# Copy the app (dockerignore excludes storage/logs, .env, vendor).
COPY . .

# Writable storage + uploads.
RUN mkdir -p storage/logs storage/cache storage/uploads uploads \
    && chown -R www-data:www-data storage uploads \
    && chmod 0775 storage uploads

# Healthcheck — /health returns 200 when DB + migrations are ready.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -sf http://localhost/health || exit 1

EXPOSE 80

# entrypoint.sh waits for MySQL then runs migrations (if INIT_DB=1).
COPY deploy/docker/entrypoint.sh /usr/local/bin/mojavez-entrypoint.sh
RUN chmod +x /usr/local/bin/mojavez-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/mojavez-entrypoint.sh"]
CMD ["apache2-foreground"]
