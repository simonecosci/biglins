#!/bin/sh
set -e

cd /app

mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

mkdir -p /run/php
chown www-data:www-data /run/php

if [ "$DB_CONNECTION" = "sqlite" ] && [ -n "$DB_DATABASE" ]; then
    mkdir -p "$(dirname "$DB_DATABASE")"
    [ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"
    chown -R www-data:www-data "$(dirname "$DB_DATABASE")"
fi

if [ -z "$APP_KEY" ]; then
    echo "WARNING: APP_KEY is not set."
fi

if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link || true

chown -R www-data:www-data storage bootstrap/cache

# --- SSL certificate setup --------------------------------------------------
SSL_MODE="${SSL_MODE:-none}"
CERTS_DIR=/data/certs
NGINX_CERT_DIR=/etc/nginx/certs
SITES_ENABLED=/etc/nginx/sites-enabled
SITES_AVAILABLE=/etc/nginx/sites-available

DOMAIN=$(echo "$APP_URL" | sed -E 's#^[a-zA-Z]+://##; s#[:/].*$##')

mkdir -p "$NGINX_CERT_DIR"

enable_http_only() {
    rm -f "$SITES_ENABLED"/app.conf "$SITES_ENABLED"/app-ssl-http.conf "$SITES_ENABLED"/app-ssl-https.conf
    ln -sf "$SITES_AVAILABLE"/app.conf "$SITES_ENABLED"/app.conf
}

enable_ssl() {
    rm -f "$SITES_ENABLED"/app.conf "$SITES_ENABLED"/app-ssl-http.conf "$SITES_ENABLED"/app-ssl-https.conf
    ln -sf "$SITES_AVAILABLE"/app-ssl-http.conf "$SITES_ENABLED"/app-ssl-http.conf
    ln -sf "$SITES_AVAILABLE"/app-ssl-https.conf "$SITES_ENABLED"/app-ssl-https.conf
}

case "$SSL_MODE" in
    none)
        enable_http_only
        ;;
    selfsigned)
        mkdir -p "$CERTS_DIR/selfsigned"
        if [ ! -f "$CERTS_DIR/selfsigned/fullchain.pem" ]; then
            openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
                -keyout "$CERTS_DIR/selfsigned/privkey.pem" \
                -out "$CERTS_DIR/selfsigned/fullchain.pem" \
                -subj "/CN=$DOMAIN"
        fi
        ln -sf "$CERTS_DIR/selfsigned/fullchain.pem" "$NGINX_CERT_DIR/fullchain.pem"
        ln -sf "$CERTS_DIR/selfsigned/privkey.pem" "$NGINX_CERT_DIR/privkey.pem"
        enable_ssl
        ;;
    custom)
        if [ ! -f "$CERTS_DIR/custom/fullchain.pem" ] || [ ! -f "$CERTS_DIR/custom/privkey.pem" ]; then
            echo "ERROR: SSL_MODE=custom requires $CERTS_DIR/custom/fullchain.pem and $CERTS_DIR/custom/privkey.pem" >&2
            exit 1
        fi
        ln -sf "$CERTS_DIR/custom/fullchain.pem" "$NGINX_CERT_DIR/fullchain.pem"
        ln -sf "$CERTS_DIR/custom/privkey.pem" "$NGINX_CERT_DIR/privkey.pem"
        enable_ssl
        ;;
    certbot)
        if [ -z "$CERTBOT_EMAIL" ]; then
            echo "ERROR: SSL_MODE=certbot requires CERTBOT_EMAIL to be set" >&2
            exit 1
        fi
        LE_DIR="$CERTS_DIR/letsencrypt"
        mkdir -p "$LE_DIR" /var/www/certbot
        if [ ! -f "$LE_DIR/live/$DOMAIN/fullchain.pem" ]; then
            rm -f "$SITES_ENABLED"/app.conf "$SITES_ENABLED"/app-ssl-http.conf "$SITES_ENABLED"/app-ssl-https.conf
            ln -sf "$SITES_AVAILABLE"/app-ssl-http.conf "$SITES_ENABLED"/app-ssl-http.conf
            nginx -g "daemon off;" &
            NGINX_BOOTSTRAP_PID=$!
            sleep 1
            certbot certonly --webroot -w /var/www/certbot -d "$DOMAIN" \
                --email "$CERTBOT_EMAIL" --agree-tos --non-interactive \
                --config-dir "$LE_DIR" --work-dir "$LE_DIR" --logs-dir "$LE_DIR"
            kill "$NGINX_BOOTSTRAP_PID"
            wait "$NGINX_BOOTSTRAP_PID" 2>/dev/null || true
        fi
        ln -sf "$LE_DIR/live/$DOMAIN/fullchain.pem" "$NGINX_CERT_DIR/fullchain.pem"
        ln -sf "$LE_DIR/live/$DOMAIN/privkey.pem" "$NGINX_CERT_DIR/privkey.pem"
        enable_ssl
        ;;
    *)
        echo "ERROR: unknown SSL_MODE '$SSL_MODE' (expected none, selfsigned, certbot, or custom)" >&2
        exit 1
        ;;
esac
# --- end SSL certificate setup ----------------------------------------------

exec "$@"
