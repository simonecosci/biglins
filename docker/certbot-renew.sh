#!/bin/sh
set -e

SSL_MODE="${SSL_MODE:-none}"

if [ "$SSL_MODE" != "certbot" ]; then
    exec sleep infinity
fi

LE_DIR=/data/certs/letsencrypt

while true; do
    certbot renew --quiet --deploy-hook "nginx -s reload" \
        --config-dir "$LE_DIR" --work-dir "$LE_DIR" --logs-dir "$LE_DIR"
    sleep 12h
done
