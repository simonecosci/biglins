# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.4

# ---------------------------------------------------------------------------
# Common PHP base (Debian trixie)
# ---------------------------------------------------------------------------
FROM debian:trixie-slim AS php-base

ARG PHP_VERSION

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        unzip \
        php${PHP_VERSION}-cli \
        php${PHP_VERSION}-fpm \
        php${PHP_VERSION}-bcmath \
        php${PHP_VERSION}-curl \
        php${PHP_VERSION}-gd \
        php${PHP_VERSION}-intl \
        php${PHP_VERSION}-mbstring \
        php${PHP_VERSION}-mysql \
        php${PHP_VERSION}-opcache \
        php${PHP_VERSION}-sqlite3 \
        php${PHP_VERSION}-xml \
        php${PHP_VERSION}-zip \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/local.ini /etc/php/${PHP_VERSION}/fpm/conf.d/99-local.ini
COPY docker/php/local.ini /etc/php/${PHP_VERSION}/cli/conf.d/99-local.ini
COPY docker/php/www.conf /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
COPY docker/php/www-rootless.conf /etc/php/${PHP_VERSION}/fpm/pool.d/www-rootless.conf.available

WORKDIR /app

# ---------------------------------------------------------------------------
# Composer dependencies + frontend build
#
# Node runs here (not in an isolated stage) because the Wayfinder Vite
# plugin shells out to `php artisan wayfinder:generate` during `vite build`.
# ---------------------------------------------------------------------------
FROM php-base AS vendor

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY . .
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader

RUN npm ci && npm run build && rm -rf node_modules

# ---------------------------------------------------------------------------
# Runtime image: php-fpm + nginx, supervised
# ---------------------------------------------------------------------------
FROM php-base AS final

ARG PHP_VERSION
ENV PHP_VERSION=${PHP_VERSION}

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        certbot \
        openssl \
        gettext-base \
        libnss-wrapper \
    && rm -rf /var/lib/apt/lists/*

COPY docker/nginx/app.conf /etc/nginx/sites-available/app.conf
COPY docker/nginx/app-ssl-http.conf /etc/nginx/sites-available/app-ssl-http.conf
COPY docker/nginx/app-ssl-https.conf /etc/nginx/sites-available/app-ssl-https.conf
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /etc/nginx/sites-available/app.conf /etc/nginx/sites-enabled/app.conf

COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/certbot-renew.sh /usr/local/bin/certbot-renew.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/certbot-renew.sh

COPY --from=vendor /app /app
COPY docker/healthcheck.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/healthcheck.sh

RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
        /data /certs /var/www/certbot /etc/nginx/certs \
    && chown -R www-data:www-data /app storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chgrp -R 0 /app /data /certs /var/www/certbot /etc/nginx/certs /run /var/lib/nginx /var/log \
        /etc/nginx/sites-available /etc/nginx/sites-enabled "/etc/php/${PHP_VERSION}/fpm/pool.d" \
    && chmod -R g=u /app /data /certs /var/www/certbot /etc/nginx/certs /run /var/lib/nginx /var/log \
        /etc/nginx/sites-available /etc/nginx/sites-enabled "/etc/php/${PHP_VERSION}/fpm/pool.d"

EXPOSE 8080 8443

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD ["/usr/local/bin/healthcheck.sh"]

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf", "-n"]
