#!/bin/sh
set -e

SSL_MODE="${SSL_MODE:-none}"

if [ "$SSL_MODE" = "none" ]; then
    curl -fsS http://localhost/ -o /dev/null
else
    curl -fsSk https://localhost/ -o /dev/null
fi
