# HTTPS Support (SSL_MODE) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add HTTPS termination to the Docker runtime image, selectable via a single `SSL_MODE` environment variable (`none`/`selfsigned`/`certbot`/`custom`), plus a container `HEALTHCHECK`, with zero behavior change for existing deployments that don't opt in.

**Architecture:** A new `certs` Docker volume at `/data/certs` persists certificate state across restarts. `docker/entrypoint.sh` derives the TLS domain from `APP_URL`, provisions/validates the right certificate pair for the selected `SSL_MODE`, symlinks it to two fixed paths nginx always reads, and enables the matching nginx site conf(s) before supervisord starts the real process tree. A new supervisor program keeps certbot's renewal loop alive (a harmless no-op outside `SSL_MODE=certbot`). A new `HEALTHCHECK` script curls the container's own internal `localhost` (not the externally-mapped `APP_URL`).

**Tech Stack:** Debian trixie-slim, nginx, certbot, openssl, supervisord, POSIX `sh` (matching the existing `entrypoint.sh` shebang and style) — no new language/runtime.

**Spec:** [docs/superpowers/specs/2026-08-15-https-support-design.md](../specs/2026-08-15-https-support-design.md)

## Global Constraints

- `SSL_MODE` defaults to `none` when unset; that mode's behavior must be byte-for-byte identical to today's (HTTP only on `:80`, `app.conf` unchanged).
- Docker/infra only — no PHP application code changes, no Pest tests apply.
- Any SSL setup failure in the entrypoint (missing `CERTBOT_EMAIL`, missing custom cert files, unknown `SSL_MODE` value) is a fatal `exit 1` — never fall back to serving the wrong protocol silently.
- Certificate state lives only under `/data/certs` (the persisted volume); nginx never reads that path directly, only the fixed symlinks at `/etc/nginx/certs/{fullchain,privkey}.pem`.
- Every shell script independently defaults `SSL_MODE="${SSL_MODE:-none}"` at its own top — `HEALTHCHECK` and supervisor-managed scripts are separate process invocations that do not inherit shell variables computed inside `entrypoint.sh`, only the container's actual configured environment.

---

## Task 1: New nginx SSL site confs + Dockerfile packaging

**Files:**
- Create: `docker/nginx/app-ssl-http.conf`
- Create: `docker/nginx/app-ssl-https.conf`
- Modify: `Dockerfile` (`final` stage: add `certbot`, `openssl` packages; `COPY` the two new conf files into `sites-available`)

**Interfaces:**
- Produces: `/etc/nginx/sites-available/app-ssl-http.conf` and `/etc/nginx/sites-available/app-ssl-https.conf` inside the image, read by `entrypoint.sh` in Task 3 (not yet enabled by anything in this task).
- Produces: `ssl_certificate /etc/nginx/certs/fullchain.pem;` / `ssl_certificate_key /etc/nginx/certs/privkey.pem;` as the fixed paths every later task's symlink must target.

- [ ] **Step 1: Create `docker/nginx/app-ssl-http.conf`**

```nginx
server {
    listen 80;
    server_name _;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}
```

- [ ] **Step 2: Create `docker/nginx/app-ssl-https.conf`**

```nginx
server {
    listen 443 ssl;
    server_name _;
    root /app/public;
    index index.php;

    client_max_body_size 32M;

    ssl_certificate /etc/nginx/certs/fullchain.pem;
    ssl_certificate_key /etc/nginx/certs/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_HOST $http_host;
        fastcgi_read_timeout 120;
        fastcgi_buffer_size 32k;
        fastcgi_buffers 16 16k;
    }

    location ~* \.(?:css|js|woff2?|ttf|svg|jpg|jpeg|png|gif|ico)$ {
        expires 7d;
        access_log off;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

- [ ] **Step 3: Modify `Dockerfile`'s `final` stage to install `certbot`/`openssl` and copy the new confs**

In the `final` stage, change:

```dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
    && rm -rf /var/lib/apt/lists/*

COPY docker/nginx/app.conf /etc/nginx/sites-available/app.conf
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /etc/nginx/sites-available/app.conf /etc/nginx/sites-enabled/app.conf
```

to:

```dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        certbot \
        openssl \
    && rm -rf /var/lib/apt/lists/*

COPY docker/nginx/app.conf /etc/nginx/sites-available/app.conf
COPY docker/nginx/app-ssl-http.conf /etc/nginx/sites-available/app-ssl-http.conf
COPY docker/nginx/app-ssl-https.conf /etc/nginx/sites-available/app-ssl-https.conf
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /etc/nginx/sites-available/app.conf /etc/nginx/sites-enabled/app.conf
```

The build-time symlink still only enables `app.conf`, so default behavior is unaffected until Task 3 wires `entrypoint.sh` to choose between them at runtime.

- [ ] **Step 4: Build and verify default behavior is unchanged**

Run: `docker compose build app && docker compose up -d && curl -sI http://localhost:${APP_PORT:-8080}/`
Expected: `HTTP/1.1 200 OK` (or a redirect to `/login`), identical to pre-change behavior. Then `docker compose down`.

- [ ] **Step 5: Verify the two new confs are syntactically valid nginx**

Run:
```bash
docker compose run --rm --entrypoint sh app -c '
  set -e
  mkdir -p /etc/nginx/certs /var/www/certbot
  openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
    -keyout /etc/nginx/certs/privkey.pem -out /etc/nginx/certs/fullchain.pem \
    -subj "/CN=localhost"
  rm -f /etc/nginx/sites-enabled/app.conf
  ln -sf /etc/nginx/sites-available/app-ssl-http.conf /etc/nginx/sites-enabled/app-ssl-http.conf
  ln -sf /etc/nginx/sites-available/app-ssl-https.conf /etc/nginx/sites-enabled/app-ssl-https.conf
  nginx -t
'
```
Expected: `nginx: configuration file /etc/nginx/nginx.conf test is successful`

- [ ] **Step 6: Commit**

```bash
git add docker/nginx/app-ssl-http.conf docker/nginx/app-ssl-https.conf Dockerfile
git commit -m "feat: add nginx SSL site confs and certbot/openssl packages"
```

---

## Task 2: docker-compose.yml — certs volume and HTTPS port

**Files:**
- Modify: `docker-compose.yml`

**Interfaces:**
- Produces: `/data/certs` as a persisted path inside the `app` container, consumed by `entrypoint.sh` in Task 3 as `$CERTS_DIR`.

- [ ] **Step 1: Add the `certs` volume mount and HTTPS port mapping**

Change:

```yaml
    ports:
      - '${APP_PORT:-8080}:80'
    env_file:
      - .env
    environment:
      DB_DATABASE: /data/database.sqlite
      APP_URL: http://localhost:${APP_PORT:-8080}
    volumes:
      - storage:/app/storage
      - database:/data
      - logos:/app/public/images/companies
    restart: unless-stopped

volumes:
  storage:
  database:
  logos:
```

to:

```yaml
    ports:
      - '${APP_PORT:-8080}:80'
      - '${APP_HTTPS_PORT:-8443}:443'
    env_file:
      - .env
    environment:
      DB_DATABASE: /data/database.sqlite
      APP_URL: http://localhost:${APP_PORT:-8080}
    volumes:
      - storage:/app/storage
      - database:/data
      - logos:/app/public/images/companies
      - certs:/data/certs
    restart: unless-stopped

volumes:
  storage:
  database:
  logos:
  certs:
```

- [ ] **Step 2: Verify the compose file is valid and default behavior is unaffected**

Run: `docker compose config --quiet && docker compose up -d && curl -sI http://localhost:${APP_PORT:-8080}/`
Expected: no error from `config`; same `200`/redirect as before. Then `docker compose down`.

- [ ] **Step 3: Commit**

```bash
git add docker-compose.yml
git commit -m "feat: add certs volume and HTTPS port mapping to docker-compose"
```

---

## Task 3: entrypoint.sh — SSL_MODE provisioning logic

**Files:**
- Modify: `docker/entrypoint.sh`

**Interfaces:**
- Consumes: `$APP_URL` (existing env var), `$SSL_MODE`, `$CERTBOT_EMAIL` (new env vars); `/etc/nginx/sites-available/{app,app-ssl-http,app-ssl-https}.conf` (Task 1); `/data/certs` (Task 2).
- Produces: `/etc/nginx/certs/fullchain.pem` and `/etc/nginx/certs/privkey.pem` (fixed symlink targets); the correct site conf(s) symlinked into `/etc/nginx/sites-enabled/`. Later tasks (renewal script) rely on the exact path `$CERTS_DIR/letsencrypt` (`/data/certs/letsencrypt`) for certbot's `--config-dir`/`--work-dir`/`--logs-dir`.

- [ ] **Step 1: Add the SSL setup block to `docker/entrypoint.sh`, before `exec "$@"`**

Change the end of the file from:

```sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link || true

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
```

to:

```sh
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
```

- [ ] **Step 2: Verify `SSL_MODE=none` (default) is unchanged**

Run: `docker compose build app && docker compose up -d && curl -sI http://localhost:${APP_PORT:-8080}/`
Expected: identical to Task 1/2 verification — plain HTTP, no `:443`.
Run: `curl -sk https://localhost:${APP_HTTPS_PORT:-8443}/` — expected: connection refused (nothing listening on 443).
`docker compose down`

- [ ] **Step 3: Verify `SSL_MODE=selfsigned`**

Run:
```bash
SSL_MODE=selfsigned docker compose up -d
curl -sI -k https://localhost:${APP_HTTPS_PORT:-8443}/
curl -sI http://localhost:${APP_PORT:-8080}/
```
Expected: the `https` request returns `200`/redirect with a valid (self-signed) TLS handshake; the plain `http` request returns `301` to `https://localhost/...`.

Run: `docker compose restart app && sleep 3 && docker compose exec app md5sum /data/certs/selfsigned/fullchain.pem`
Run it twice across a restart — expected: identical checksum both times (no regeneration).
`docker compose down`

- [ ] **Step 4: Verify `SSL_MODE=custom`**

Run:
```bash
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -keyout /tmp/privkey.pem -out /tmp/fullchain.pem -subj "/CN=localhost"
docker volume create biglins_certs 2>/dev/null || true
CID=$(docker create -v biglins_certs:/data alpine)
docker cp /tmp/fullchain.pem "$CID":/data/custom/fullchain.pem
docker cp /tmp/privkey.pem "$CID":/data/custom/privkey.pem
docker rm "$CID"
SSL_MODE=custom docker compose up -d
curl -sI -k https://localhost:${APP_HTTPS_PORT:-8443}/
```
Expected: `200`/redirect over TLS using the dropped-in cert.

Then remove the volume's `custom/` files and rerun `SSL_MODE=custom docker compose up -d` — expected: container exits non-zero, logs show `ERROR: SSL_MODE=custom requires ...`.
`docker compose down`

- [ ] **Step 5: Verify `SSL_MODE=certbot` fails fast without `CERTBOT_EMAIL`**

Run: `SSL_MODE=certbot docker compose up`
Expected: container exits non-zero, logs show `ERROR: SSL_MODE=certbot requires CERTBOT_EMAIL to be set`. (Full issuance against a real reachable domain isn't exercisable in this environment — covered by a read-through of the bootstrap logic instead.)
`docker compose down`

- [ ] **Step 6: Verify an unknown `SSL_MODE` value fails fast**

Run: `SSL_MODE=bogus docker compose up`
Expected: container exits non-zero, logs show `ERROR: unknown SSL_MODE 'bogus' ...`.
`docker compose down`

- [ ] **Step 7: `shellcheck` the modified script (if available)**

Run: `shellcheck docker/entrypoint.sh || true`
Expected: no new warnings beyond whatever the file already had before this change (note and fix anything clearly related to the new block).

- [ ] **Step 8: Commit**

```bash
git add docker/entrypoint.sh
git commit -m "feat: provision SSL certificates per SSL_MODE in the entrypoint"
```

---

## Task 4: Certbot renewal — supervisor program + script

**Files:**
- Create: `docker/certbot-renew.sh`
- Modify: `docker/supervisor/supervisord.conf`
- Modify: `Dockerfile` (`COPY` + `chmod` the new script)

**Interfaces:**
- Consumes: `$SSL_MODE` (independently defaulted, per Global Constraints); `/data/certs/letsencrypt` (same path Task 3's `certbot` branch uses as `$LE_DIR`).

- [ ] **Step 1: Create `docker/certbot-renew.sh`**

```sh
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
```

- [ ] **Step 2: Add the `certbot-renew` program to `docker/supervisor/supervisord.conf`**

Append after the `[program:queue-worker]` block:

```ini
[program:certbot-renew]
command=/usr/local/bin/certbot-renew.sh
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
```

- [ ] **Step 3: Copy and make the script executable in `Dockerfile`**

Change:

```dockerfile
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
```

to:

```dockerfile
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/certbot-renew.sh /usr/local/bin/certbot-renew.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/certbot-renew.sh
```

- [ ] **Step 4: Verify the program runs idle outside `certbot` mode**

Run:
```bash
docker compose build app && docker compose up -d
sleep 3
docker compose exec app supervisorctl status certbot-renew
```
Expected: `certbot-renew RUNNING ...` (the `sleep infinity` no-op keeps it alive without crash-looping).
`docker compose down`

- [ ] **Step 5: `shellcheck` the new script (if available)**

Run: `shellcheck docker/certbot-renew.sh || true`
Expected: no warnings.

- [ ] **Step 6: Commit**

```bash
git add docker/certbot-renew.sh docker/supervisor/supervisord.conf Dockerfile
git commit -m "feat: add certbot renewal supervisor program"
```

---

## Task 5: Container HEALTHCHECK

**Files:**
- Create: `docker/healthcheck.sh`
- Modify: `Dockerfile` (`COPY`, `chmod`, `HEALTHCHECK` instruction)

**Interfaces:**
- Consumes: `$SSL_MODE` (independently defaulted, per Global Constraints).

- [ ] **Step 1: Create `docker/healthcheck.sh`**

```sh
#!/bin/sh
set -e

SSL_MODE="${SSL_MODE:-none}"

if [ "$SSL_MODE" = "none" ]; then
    curl -fsS http://localhost/ -o /dev/null
else
    curl -fsSk https://localhost/ -o /dev/null
fi
```

- [ ] **Step 2: Add the `HEALTHCHECK` instruction to `Dockerfile`**

Change:

```dockerfile
COPY --from=vendor /app /app

RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data /app storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf", "-n"]
```

to:

```dockerfile
COPY --from=vendor /app /app
COPY docker/healthcheck.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/healthcheck.sh

RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data /app storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80 443

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD ["/usr/local/bin/healthcheck.sh"]

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf", "-n"]
```

- [ ] **Step 3: Verify health status in `none` and `selfsigned` modes**

Run:
```bash
docker compose build app && docker compose up -d
sleep 35
docker inspect --format='{{json .State.Health.Status}}' $(docker compose ps -q app)
```
Expected: `"healthy"`.
`docker compose down`

Repeat with `SSL_MODE=selfsigned docker compose up -d` — expected: `"healthy"` again (the `-k` branch handles the self-signed cert).
`docker compose down`

- [ ] **Step 4: `shellcheck` the new script (if available)**

Run: `shellcheck docker/healthcheck.sh || true`
Expected: no warnings.

- [ ] **Step 5: Commit**

```bash
git add docker/healthcheck.sh Dockerfile
git commit -m "feat: add container HEALTHCHECK"
```

---

## Task 6: Documentation — .env, README, wiki

**Files:**
- Modify: `.env.example`
- Modify: `.env` (local, untracked — not part of the git commit)
- Modify: `README.md`
- Modify (external repo): `biglins.wiki/Quick-Start.md`

**Interfaces:**
- None — documentation only, no code interface.

- [ ] **Step 1: Add the new variables to `.env.example`, right after `APP_URL`**

Change:

```
APP_NAME=Biglins
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8080

APP_LOCALE=en
```

to:

```
APP_NAME=Biglins
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8080

# Docker only — see README "Docker setup". Ignored by `php artisan serve`.
# SSL_MODE=none          # none | selfsigned | certbot | custom
# CERTBOT_EMAIL=

APP_LOCALE=en
```

- [ ] **Step 2: Mirror the same two commented lines into the local `.env`**

Apply the identical addition to `.env` (same insertion point). This file is gitignored — do not `git add` it.

- [ ] **Step 3: Extend the `## Docker setup` section in `README.md`**

Change:

```markdown
## Docker setup

```bash
docker compose up -d --build
docker compose run --rm app php artisan migrate --force   # first run only
```

The app runs at `http://localhost:8080` (port configurable via `APP_PORT` in `.env`). See [Dockerfile](Dockerfile) and [docker-compose.yml](docker-compose.yml) for image details: PHP-FPM + nginx + queue worker on Debian trixie, managed by supervisord.
```

to:

```markdown
## Docker setup

```bash
docker compose up -d --build
docker compose run --rm app php artisan migrate --force   # first run only
```

The app runs at `http://localhost:8080` (port configurable via `APP_PORT` in `.env`). See [Dockerfile](Dockerfile) and [docker-compose.yml](docker-compose.yml) for image details: PHP-FPM + nginx + queue worker on Debian trixie, managed by supervisord.

### HTTPS

Set `SSL_MODE` in `.env` to terminate TLS at nginx (default `none` — HTTP only, unchanged):

| `SSL_MODE` | Behavior |
|---|---|
| `none` (default) | HTTP only on `:80`. |
| `selfsigned` | Self-signed certificate generated for `APP_URL`'s host, persisted in the `certs` volume. `:80` redirects to `:443`. |
| `certbot` | Let's Encrypt certificate via HTTP-01, auto-renewed daily. Requires `APP_URL` to be a publicly reachable domain and `CERTBOT_EMAIL` to be set. |
| `custom` | Bring your own certificate: place `fullchain.pem`/`privkey.pem` issued by your own CA into the `certs` volume under `custom/` before starting the container. |

HTTPS is served on `${APP_HTTPS_PORT:-8443}` (host) → `:443` (container).
```

- [ ] **Step 4: Mirror the same `### HTTPS` subsection into the wiki**

```bash
rm -rf /tmp/biglins-wiki
git clone https://github.com/simonecosci/biglins.wiki.git /tmp/biglins-wiki
```

Apply the identical `### HTTPS` addition to `/tmp/biglins-wiki/Quick-Start.md`'s `## Docker Setup` section (same table as README Step 3, wording match the wiki's existing terse style).

- [ ] **Step 5: Commit and push the wiki change — ask for explicit confirmation first**

This pushes to a separate, publicly-visible repo (`biglins.wiki`) outside the main repo's history. Confirm with the user before pushing. Once confirmed:

```bash
cd /tmp/biglins-wiki
git add Quick-Start.md
git commit -m "docs: document HTTPS / SSL_MODE Docker setup"
git push origin master
```

- [ ] **Step 6: Commit the main-repo documentation changes**

```bash
git add .env.example README.md
git commit -m "docs: document SSL_MODE, CERTBOT_EMAIL, and APP_HTTPS_PORT"
```

---

## Final verification (after all tasks)

- [ ] Full `docker compose up -d --build` with no `SSL_MODE` set still serves plain HTTP exactly as before the plan started.
- [ ] `git log --oneline` shows one commit per task, each independently buildable.
- [ ] Re-read the spec end to end and confirm every section (Configuration, Architecture, nginx configuration, Entrypoint logic, Certbot bootstrap, Renewal, Dockerfile changes, docker-compose.yml changes, Container healthcheck, Documentation updates) has a corresponding task above.
