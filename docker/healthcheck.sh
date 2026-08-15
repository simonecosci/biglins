#!/bin/sh
set -e

[ -f /run/app-ports.env ] && . /run/app-ports.env

HTTP_PORT="${HTTP_PORT:-8080}"
HTTPS_PORT="${HTTPS_PORT:-8443}"
SSL_MODE="${SSL_MODE:-none}"

if [ "$SSL_MODE" = "none" ]; then
    curl -fsS "http://localhost:${HTTP_PORT}/" -o /dev/null
else
    curl -fsSk "https://localhost:${HTTPS_PORT}/" -o /dev/null
fi
