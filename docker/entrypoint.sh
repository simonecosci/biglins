#!/bin/sh
set -e

cd /app

IS_ROOT=0
[ "$(id -u)" = "0" ] && IS_ROOT=1

if [ "$IS_ROOT" = "1" ]; then
    HTTP_PORT="${HTTP_PORT:-80}"
    HTTPS_PORT="${HTTPS_PORT:-443}"
else
    HTTP_PORT="${HTTP_PORT:-8080}"
    HTTPS_PORT="${HTTPS_PORT:-8443}"
fi
export HTTP_PORT HTTPS_PORT

# --- rootless support: synthesize a passwd/group entry via nss_wrapper ------
# Arbitrary UIDs assigned by Kubernetes/OpenShift have no /etc/passwd entry,
# which breaks getpwuid()-dependent tooling (openssl, some PHP extensions).
if [ "$IS_ROOT" != "1" ]; then
    CURRENT_UID="$(id -u)"
    CURRENT_GID="$(id -g)"
    if ! getent passwd "$CURRENT_UID" >/dev/null 2>&1; then
        export NSS_WRAPPER_PASSWD=/tmp/nss-wrapper-passwd
        export NSS_WRAPPER_GROUP=/tmp/nss-wrapper-group
        cp /etc/passwd "$NSS_WRAPPER_PASSWD"
        cp /etc/group "$NSS_WRAPPER_GROUP"
        echo "appuser:x:${CURRENT_UID}:${CURRENT_GID}:App User:/tmp:/bin/false" >> "$NSS_WRAPPER_PASSWD"
        getent group "$CURRENT_GID" >/dev/null 2>&1 || echo "appuser:x:${CURRENT_GID}:" >> "$NSS_WRAPPER_GROUP"
        export LD_PRELOAD="libnss_wrapper.so"
    fi
fi

# Create a dir and, when non-root and the process owns it, guarantee it's
# group-writable regardless of umask. For dirs the process just created, it
# owns them, so the chmod applies and is expected to succeed under `set -e`.
# For dirs that pre-exist from the image (owned by www-data, group 0 already
# made writable at build time per Task 4), a non-owning UID cannot chmod
# them even though it can already write into them — chmod requires file
# ownership, not just group write access — so the chmod is skipped entirely
# rather than attempted-and-swallowed; the baked group-write bit already
# provides what's needed there.
ensure_writable_dir() {
    mkdir -p "$1"
    if [ "$IS_ROOT" != "1" ] && [ "$(stat -c %u "$1")" = "$CURRENT_UID" ]; then
        chmod -R g+rwX "$1"
    fi
}

ensure_writable_dir storage/framework/cache
ensure_writable_dir storage/framework/sessions
ensure_writable_dir storage/framework/testing
ensure_writable_dir storage/framework/views
ensure_writable_dir storage/logs
ensure_writable_dir bootstrap/cache
[ "$IS_ROOT" = "1" ] && chown -R www-data:www-data storage bootstrap/cache

ensure_writable_dir /run/php
[ "$IS_ROOT" = "1" ] && chown www-data:www-data /run/php

if [ "$DB_CONNECTION" = "sqlite" ] && [ -n "$DB_DATABASE" ]; then
    ensure_writable_dir "$(dirname "$DB_DATABASE")"
    [ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"
    [ "$IS_ROOT" = "1" ] && chown -R www-data:www-data "$(dirname "$DB_DATABASE")"
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

[ "$IS_ROOT" = "1" ] && chown -R www-data:www-data storage bootstrap/cache

# --- SSL certificate setup + nginx/php-fpm runtime config -------------------
# Only provision certificates and render server config when this invocation
# actually starts the server process tree (the image's default CMD). One-off
# `docker compose run ...` commands skip straight to `exec "$@"`.
if [ "$1" = "/usr/bin/supervisord" ]; then
    if [ "$IS_ROOT" != "1" ]; then
        cp "/etc/php/${PHP_VERSION}/fpm/pool.d/www-rootless.conf.available" \
            "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
    fi

    for conf in app.conf app-ssl-http.conf app-ssl-https.conf; do
        envsubst '${HTTP_PORT} ${HTTPS_PORT}' \
            < "/etc/nginx/sites-available/$conf" > "/tmp/$conf"
        mv "/tmp/$conf" "/etc/nginx/sites-available/$conf"
    done

    {
        echo "HTTP_PORT=$HTTP_PORT"
        echo "HTTPS_PORT=$HTTPS_PORT"
    } > /run/app-ports.env

    SSL_MODE="${SSL_MODE:-none}"
    CERTS_DIR=/certs
    NGINX_CERT_DIR=/etc/nginx/certs
    SITES_ENABLED=/etc/nginx/sites-enabled
    SITES_AVAILABLE=/etc/nginx/sites-available

    DOMAIN=$(echo "$APP_URL" | sed -E 's#^[a-zA-Z]+://##; s#[:/].*$##')

    ensure_writable_dir "$NGINX_CERT_DIR"

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
            ensure_writable_dir "$CERTS_DIR/selfsigned"
            if [ ! -f "$CERTS_DIR/selfsigned/fullchain.pem" ]; then
                openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
                    -keyout "$CERTS_DIR/selfsigned/privkey.pem" \
                    -out "$CERTS_DIR/selfsigned/fullchain.pem" \
                    -subj "/CN=$DOMAIN"
                chmod 600 "$CERTS_DIR/selfsigned/privkey.pem"
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
            ensure_writable_dir "$LE_DIR"
            ensure_writable_dir /var/www/certbot
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
fi
# --- end SSL certificate setup ----------------------------------------------

exec "$@"
