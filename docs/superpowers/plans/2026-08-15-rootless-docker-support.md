# Rootless Docker Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Docker image runnable both as root (current `docker-compose.yml` flow, unchanged) and as an arbitrary non-root UID sharing GID 0 (the OpenShift/K8s/Podman arbitrary-UID convention), auto-detected at container start.

**Architecture:** `entrypoint.sh` detects `id -u` once and branches. Root behaves exactly as today. Non-root switches to unprivileged ports (8080/8443) via `envsubst`-rendered nginx configs, skips `chown` in favor of GID-0 group-writable permissions baked into the image at build time, swaps in a php-fpm pool file without `user`/`group` directives (php-fpm refuses to start otherwise when not root), drops `supervisord.conf`'s now-unnecessary `user=root` line, and synthesizes an `/etc/passwd` entry via `nss_wrapper` for UIDs with no existing entry.

**Tech Stack:** Debian trixie-slim, nginx, php-fpm, supervisord, `gettext-base` (envsubst), `libnss-wrapper`.

**Spec:** [docs/superpowers/specs/2026-08-15-rootless-docker-support-design.md](../specs/2026-08-15-rootless-docker-support-design.md)

## Global Constraints

- The existing root-mode `docker-compose.yml` flow must keep working byte-for-byte unchanged (ports 80/443 externally mapped to `${APP_PORT:-8080}`/`${APP_HTTPS_PORT:-8443}`, same volumes, same env vars).
- No build args, no separate Dockerfile/image variant — one image, runtime-detected.
- Supported non-root convention is arbitrary UID + GID 0 (OpenShift SCC default, or `docker run --user UID:0`). UID with a non-zero, non-shared GID is explicitly unsupported (documented, not handled in code).
- `SSL_MODE=certbot` needs no special-casing for rootless — it's the orchestrator's job to map external 80/443 to whatever port the container actually listens on.
- This is infrastructure, not application code — no Pest coverage applies. Verification is manual `docker build`/`docker run` smoke tests.

---

### Task 1: Rootless php-fpm pool config

**Files:**
- Create: `docker/php/www-rootless.conf`

**Interfaces:**
- Produces: a php-fpm pool file with no `user`/`group`/`listen.owner`/`listen.group` directives (php-fpm's master refuses to start those when it isn't running as root), consumed by `entrypoint.sh` in Task 5.

- [ ] **Step 1: Create the file**

```ini
[www]

listen = /run/php/php-fpm.sock
listen.mode = 0660

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4

clear_env = no
catch_workers_output = yes
decorate_workers_output = no
```

- [ ] **Step 2: Diff against the root variant to confirm the only difference is the removed user/group lines**

Run: `diff docker/php/www.conf docker/php/www-rootless.conf`
Expected output:
```
2a3
> user = www-data
> group = www-data
7,8d7
< listen.owner = www-data
< listen.group = www-data
```

- [ ] **Step 3: Commit**

```bash
git add docker/php/www-rootless.conf
git commit -m "feat: add rootless php-fpm pool config"
```

---

### Task 2: Port-templated nginx configs and healthcheck

**Files:**
- Modify: `docker/nginx/app.conf`
- Modify: `docker/nginx/app-ssl-http.conf`
- Modify: `docker/nginx/app-ssl-https.conf`
- Modify: `docker/healthcheck.sh`

**Interfaces:**
- Produces: nginx site files with `${HTTP_PORT}`/`${HTTPS_PORT}` placeholders in their `listen` directives, rendered by `envsubst '${HTTP_PORT} ${HTTPS_PORT}'` (restricting substitution to just these two names so nginx's own `$uri`/`$query_string`/etc. variables are left untouched) — wired up in Task 5. `healthcheck.sh` reads the same ports from `/run/app-ports.env`, written by `entrypoint.sh` in Task 5.

- [ ] **Step 1: Template the port in `docker/nginx/app.conf`**

Change line 2 from:
```
    listen 80;
```
to:
```
    listen ${HTTP_PORT};
```

- [ ] **Step 2: Template the port in `docker/nginx/app-ssl-http.conf`**

Change line 2 from:
```
    listen 80;
```
to:
```
    listen ${HTTP_PORT};
```

- [ ] **Step 3: Template the port in `docker/nginx/app-ssl-https.conf`**

Change line 2 from:
```
    listen 443 ssl;
```
to:
```
    listen ${HTTPS_PORT} ssl;
```

- [ ] **Step 4: Rewrite `docker/healthcheck.sh` to use the dynamic port**

Replace the full file with:

```sh
#!/bin/sh
set -e

[ -f /run/app-ports.env ] && . /run/app-ports.env

HTTP_PORT="${HTTP_PORT:-80}"
HTTPS_PORT="${HTTPS_PORT:-443}"
SSL_MODE="${SSL_MODE:-none}"

if [ "$SSL_MODE" = "none" ]; then
    curl -fsS "http://localhost:${HTTP_PORT}/" -o /dev/null
else
    curl -fsSk "https://localhost:${HTTPS_PORT}/" -o /dev/null
fi
```

- [ ] **Step 5: Verify the three nginx files only differ in the templated line**

Run: `git diff docker/nginx/`
Expected: each file shows exactly one changed `listen` line, nothing else.

- [ ] **Step 6: Commit**

```bash
git add docker/nginx/app.conf docker/nginx/app-ssl-http.conf docker/nginx/app-ssl-https.conf docker/healthcheck.sh
git commit -m "feat: template nginx listen ports for rootless support"
```

---

### Task 3: Drop supervisord's hardcoded root user

**Files:**
- Modify: `docker/supervisor/supervisord.conf`

**Interfaces:**
- Produces: a `[supervisord]` section with no `user=` directive — behaviorally identical when the container runs as root (nothing to switch to), and no longer a fatal `setuid` failure when it doesn't.

- [ ] **Step 1: Remove the `user=root` line**

Change:
```ini
[supervisord]
nodaemon=true
user=root
logfile=/dev/null
logfile_maxbytes=0
pidfile=/run/supervisord.pid
```
to:
```ini
[supervisord]
nodaemon=true
logfile=/dev/null
logfile_maxbytes=0
pidfile=/run/supervisord.pid
```

- [ ] **Step 2: Commit**

```bash
git add docker/supervisor/supervisord.conf
git commit -m "fix: remove hardcoded supervisord root user for rootless support"
```

---

### Task 4: Dockerfile — packages, rootless pool file, GID-0 permissions, ports

**Files:**
- Modify: `Dockerfile`

**Interfaces:**
- Consumes: `docker/php/www-rootless.conf` (Task 1).
- Produces: `/etc/php/${PHP_VERSION}/fpm/pool.d/www-rootless.conf.available` in the image (consumed by `entrypoint.sh` in Task 5); `envsubst` and `libnss_wrapper.so` available on `PATH`/`LD_PRELOAD` search path; `/app`, `/data`, `/certs`, `/var/www/certbot`, `/etc/nginx/certs`, `/run`, `/var/lib/nginx`, `/var/log/nginx`, `/etc/nginx/sites-available`, `/etc/nginx/sites-enabled`, and the php-fpm `pool.d` dir all group-owned by GID 0 with group permissions mirroring the owner's (the OpenShift arbitrary-UID convention), so a non-root UID sharing GID 0 can write to them; ports 8080/8443 documented via `EXPOSE` alongside the existing 80/443.

- [ ] **Step 1: Copy the rootless pool file alongside the existing one**

In the `php-base` stage, change:
```dockerfile
COPY docker/php/local.ini /etc/php/${PHP_VERSION}/fpm/conf.d/99-local.ini
COPY docker/php/local.ini /etc/php/${PHP_VERSION}/cli/conf.d/99-local.ini
COPY docker/php/www.conf /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
```
to:
```dockerfile
COPY docker/php/local.ini /etc/php/${PHP_VERSION}/fpm/conf.d/99-local.ini
COPY docker/php/local.ini /etc/php/${PHP_VERSION}/cli/conf.d/99-local.ini
COPY docker/php/www.conf /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
COPY docker/php/www-rootless.conf /etc/php/${PHP_VERSION}/fpm/pool.d/www-rootless.conf.available
```

- [ ] **Step 2: Install `gettext-base` (for `envsubst`) and `libnss-wrapper` in the final stage**

Change:
```dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        certbot \
        openssl \
    && rm -rf /var/lib/apt/lists/*
```
to:
```dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        certbot \
        openssl \
        gettext-base \
        libnss-wrapper \
    && rm -rf /var/lib/apt/lists/*
```

- [ ] **Step 3: Bake GID-0 group-writable permissions and pre-create volume mount points**

Change:
```dockerfile
RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data /app storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80 443
```
to:
```dockerfile
RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
        /data /certs /var/www/certbot /etc/nginx/certs \
    && chown -R www-data:www-data /app storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chgrp -R 0 /app /data /certs /var/www/certbot /etc/nginx/certs /run /var/lib/nginx /var/log/nginx \
        /etc/nginx/sites-available /etc/nginx/sites-enabled "/etc/php/${PHP_VERSION}/fpm/pool.d" \
    && chmod -R g=u /app /data /certs /var/www/certbot /etc/nginx/certs /run /var/lib/nginx /var/log/nginx \
        /etc/nginx/sites-available /etc/nginx/sites-enabled "/etc/php/${PHP_VERSION}/fpm/pool.d"

EXPOSE 80 443 8080 8443
```

`/data` and `/certs` are pre-created here (empty, GID-0 group-writable) purely so that when Docker/Podman first mounts a named volume at those paths, it copies the image directory's permissions into the fresh volume — the standard trick for making named-volume mount points work under an arbitrary UID. (Kubernetes PVCs don't get this treatment automatically; that's a documented caveat in Task 7, not something code can fix.)

- [ ] **Step 4: Build the image and confirm it still builds cleanly**

Run: `docker build -t biglins:rootless-wip .`
Expected: build completes successfully (exit code 0), no errors from the new `apt-get install` or `RUN` block.

- [ ] **Step 5: Commit**

```bash
git add Dockerfile
git commit -m "feat: bake GID-0 permissions and rootless tooling into the Docker image"
```

---

### Task 5: Rootless-aware entrypoint

**Files:**
- Modify: `docker/entrypoint.sh`

**Interfaces:**
- Consumes: `/etc/php/${PHP_VERSION}/fpm/pool.d/www-rootless.conf.available` (Task 4), `${HTTP_PORT}`/`${HTTPS_PORT}` placeholders in the nginx site files (Task 2).
- Produces: `/run/app-ports.env` (consumed by `docker/healthcheck.sh`, Task 2); rendered nginx site configs with concrete listen ports; `/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf` swapped to the rootless variant when non-root; `NSS_WRAPPER_PASSWD`/`NSS_WRAPPER_GROUP`/`LD_PRELOAD` exported into the process tree that `exec "$@"` hands off to.

- [ ] **Step 1: Replace the full file**

```sh
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

# Create a dir and, when non-root, guarantee it's group-writable regardless
# of umask. The process always owns whatever it just created, so this chmod
# never needs privileges beyond what mkdir itself already required.
ensure_writable_dir() {
    mkdir -p "$1"
    [ "$IS_ROOT" = "1" ] || chmod -R g+rwX "$1"
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
```

- [ ] **Step 2: Confirm the script is still valid POSIX `sh` (the container's `/bin/sh` is `dash`, which doesn't support bash-only syntax)**

Run: `dash -n docker/entrypoint.sh` (or, if `dash` isn't available locally, `sh -n docker/entrypoint.sh`)
Expected: no output, exit code 0 (syntax check only, doesn't execute).

- [ ] **Step 3: Commit**

```bash
git add docker/entrypoint.sh
git commit -m "feat: detect non-root UID and switch to rootless startup path"
```

---

### Task 6: README — document rootless usage

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Add a "Running rootless" subsection after the existing "HTTPS" section**

Insert after the line `` `certbot` and `custom` require a real `APP_URL` set in `.env` (not the default `http://localhost:8080`), since the certificate domain is derived from its host. `` (currently line 64) and before `## Useful commands`:

```markdown

### Running rootless

The image auto-detects whether it's running as root or as an arbitrary
non-root UID and adjusts itself accordingly — no build flag needed. When
started as a non-root UID:

- Listen ports switch from `80`/`443` to `8080`/`8443` (unprivileged ports
  don't require `CAP_NET_BIND_SERVICE`).
- Ownership fixups (`chown`) are skipped in favor of group-writable
  permissions baked into the image at build time, following the OpenShift
  arbitrary-UID convention: the container's runtime GID must be **0** (as a
  primary or supplementary group), either supplementary or primary.
- A synthetic `/etc/passwd` entry is generated via `nss_wrapper` for UIDs
  that don't already have one, so `getpwuid()`-dependent tooling (OpenSSL,
  Certbot, some PHP extensions) keeps working.

Example with plain `docker run`:

```bash
docker run --user 1000:0 -p 8080:8080 -p 8443:8443 --env-file .env simonecosci/biglins
```

On Kubernetes/OpenShift, set `securityContext.runAsUser` to any UID and
`securityContext.runAsGroup: 0` (OpenShift's default restricted SCC does
this automatically, so most deployments need no `securityContext` at all).

**Caveat:** for `storage`/`database`/`certs` backed by a fresh Docker/Podman
named volume, the image's baked permissions carry over automatically on
first mount. Kubernetes PersistentVolumeClaims don't get this treatment —
if your storage provisioner doesn't already grant group-0 write access,
set a matching `fsGroup` in the pod's `securityContext`.
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: document rootless Docker usage"
```

---

### Task 7: End-to-end verification

**Files:** none (verification only)

- [ ] **Step 1: Build the final image**

Run: `docker build -t biglins:rootless .`
Expected: build succeeds.

- [ ] **Step 2: Root-mode regression check (existing `docker-compose.yml` flow)**

Run:
```bash
cp .env.example .env
docker compose up -d --build
sleep 5
curl -fsS http://localhost:8080/ -o /dev/null && echo "root mode OK"
docker compose logs app --tail=50
docker compose down -v
```
Expected: `root mode OK` printed, no permission errors in the logs, HTTP 200-range response (curl exits 0).

- [ ] **Step 3: Rootless smoke test with an arbitrary UID sharing GID 0**

Run:
```bash
docker volume create biglins-rootless-storage
docker run -d --name biglins-rootless \
    --user 1000:0 \
    -p 18080:8080 \
    -e APP_KEY=$(docker run --rm biglins:rootless php artisan key:generate --show) \
    -e DB_CONNECTION=sqlite \
    -e DB_DATABASE=/data/database.sqlite \
    -e RUN_MIGRATIONS=true \
    -v biglins-rootless-storage:/app/storage \
    biglins:rootless
sleep 8
curl -fsS http://localhost:18080/ -o /dev/null && echo "rootless mode OK"
docker logs biglins-rootless --tail=80
docker rm -f biglins-rootless
docker volume rm biglins-rootless-storage
```
Expected: `rootless mode OK` printed, `docker logs` shows no `Permission denied`, `unable to change user`, or php-fpm/nginx startup failures, and no `nss_wrapper`-related `getpwuid` errors.

- [ ] **Step 4: If either check fails, diagnose against the specific error before proceeding — do not relax permissions broadly (e.g. `chmod -R 777`) as a workaround**

- [ ] **Step 5: Final commit (only if verification uncovered fixes not already committed in earlier tasks)**

```bash
git status
# If clean, nothing to do — all changes were committed per-task.
```
