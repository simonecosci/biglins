# HTTPS Support (SSL_MODE) — Design

Resolves [issue #15](https://github.com/simonecosci/biglins/issues/15).

## Purpose

The Docker image currently serves plain HTTP on port 80 only. Add first-class HTTPS support to the runtime image so a deployment can terminate TLS at nginx using one of three certificate sources, selected by a single environment variable, with zero behavior change for existing deployments that don't opt in.

## Scope

Docker/infra only — no PHP application code changes. Touches `Dockerfile`, `docker/entrypoint.sh`, `docker/nginx/*`, `docker/supervisor/supervisord.conf`, `docker-compose.yml`. New files: `docker/nginx/app-ssl-http.conf`, `docker/nginx/app-ssl-https.conf`, `docker/certbot-renew.sh`, `docker/healthcheck.sh`.

## Configuration

New environment variables, all optional except where noted:

| Variable | Values | Default | Notes |
|---|---|---|---|
| `SSL_MODE` | `none` \| `selfsigned` \| `certbot` \| `custom` | `none` | Selects the certificate strategy. `none` preserves today's HTTP-only behavior exactly. |
| `CERTBOT_EMAIL` | email address | — | Required when `SSL_MODE=certbot`. Entrypoint fails fast with a clear error if missing. |

The TLS domain is derived from the host portion of `APP_URL` (already required by the app) — no new variable for it, per the issue.

## Architecture

A single new named volume, `certs`, mounted at `/data/certs`, holds all certificate state so it survives container restarts/rebuilds:

- `/data/certs/letsencrypt/` — certbot's `--config-dir`/`--work-dir`/`--logs-dir` (account keys, `live/`, `renewal/` config).
- `/data/certs/selfsigned/` — generated self-signed `fullchain.pem` + `privkey.pem`.
- `/data/certs/custom/` — where the operator drops their own CA-issued `fullchain.pem` + `privkey.pem` before starting the container with `SSL_MODE=custom`.

nginx never reads these paths directly. The entrypoint symlinks whichever pair is active for the current mode to two fixed paths:

- `/etc/nginx/certs/fullchain.pem`
- `/etc/nginx/certs/privkey.pem`

so the HTTPS nginx conf template never changes across modes.

## nginx configuration

Three site conf files (replacing the single `app.conf` used unconditionally today):

- `app.conf` (unchanged) — HTTP only on `:80`, current behavior. Enabled when `SSL_MODE=none`.
- `app-ssl-http.conf` (new) — `:80`. Serves `/.well-known/acme-challenge/` as static files from `/var/www/certbot` (needed for certbot's HTTP-01 challenge, both initial issuance and renewal). Redirects everything else with `301` to `https://$host$request_uri`.
- `app-ssl-https.conf` (new) — `:443`. `ssl_certificate`/`ssl_certificate_key` point at the fixed symlink paths above. Same `location` blocks as `app.conf` today (PHP-FPM passthrough, static asset caching, dotfile deny).

Exactly one of `app.conf` or the `app-ssl-*.conf` pair is symlinked into `sites-enabled/` at a time, decided by the entrypoint before supervisord starts.

## Entrypoint logic

Added after the existing storage/DB setup in `docker/entrypoint.sh`, before `exec "$@"`:

1. Derive `DOMAIN` from the host portion of `APP_URL`.
2. `case "$SSL_MODE"`:
   - **`none`** (default): enable `app.conf`. No other change — identical to current behavior.
   - **`selfsigned`**: if `/data/certs/selfsigned/fullchain.pem` doesn't exist yet, generate a long-lived (10-year) self-signed cert via `openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=$DOMAIN"` into that path. No renewal needed for this mode. Symlink into place, enable `app-ssl-http.conf` + `app-ssl-https.conf`.
   - **`certbot`**: fail with a clear error if `CERTBOT_EMAIL` is unset. If `/data/certs/letsencrypt/live/$DOMAIN/fullchain.pem` doesn't exist yet, bootstrap it (see below). Symlink into place, enable both SSL confs.
   - **`custom`**: fail with a clear error if `/data/certs/custom/fullchain.pem` or `privkey.pem` is missing. Symlink into place, enable both SSL confs.
   - **anything else**: fail with a clear error naming the invalid value.
3. Any failure in this block is a fatal `exit 1` — an unstarted container is preferable to one silently serving the wrong protocol.

### Certbot bootstrap (first run only)

Chicken-and-egg: nginx's HTTPS conf can't start without a cert, and certbot's HTTP-01 challenge needs nginx already answering on `:80`. Resolved by a transient nginx:

1. Entrypoint enables only `app-ssl-http.conf` (already serves the acme-challenge location) and starts a temporary `nginx -g "daemon off;"` in the background.
2. Runs `certbot certonly --webroot -w /var/www/certbot -d "$DOMAIN" --email "$CERTBOT_EMAIL" --agree-tos --non-interactive --config-dir /data/certs/letsencrypt --work-dir /data/certs/letsencrypt --logs-dir /data/certs/letsencrypt`.
3. Stops the temporary nginx (`nginx -s stop`).
4. Falls through to the normal symlink + enable-both-confs step; supervisord then starts the real, permanent nginx.

### Renewal

New supervisor program `certbot-renew`, running `docker/certbot-renew.sh`:

- If `SSL_MODE != certbot`, the script sleeps forever (`sleep infinity`) — an intentional no-op so the program can always be `autostart=true` in `supervisord.conf` without conditional templating.
- Otherwise, loops: `certbot renew --quiet --deploy-hook "nginx -s reload" --config-dir /data/certs/letsencrypt --work-dir /data/certs/letsencrypt --logs-dir /data/certs/letsencrypt`, then `sleep 12h`. `certbot renew` is a no-op unless the cert is near expiry, so a 12h cadence is safe and satisfies the issue's "runs daily" intent.

## Dockerfile changes

Install `certbot` and `openssl` (openssl is normally already present via base packages, but pin it explicitly) alongside the existing `nginx`/`supervisor` install in the `final` stage. Copy the two new nginx conf files and `certbot-renew.sh` (`chmod +x`).

## docker-compose.yml changes

- New volume `certs:`, mounted at `/data/certs`.
- New port mapping `${APP_HTTPS_PORT:-8443}:443`.
- `SSL_MODE` and `CERTBOT_EMAIL` are left for the operator to set via `.env` / `environment:` — no default forced in the compose file beyond what the entrypoint already defaults (`none`).

## Container healthcheck

New `docker/healthcheck.sh` (new file), invoked via `HEALTHCHECK` in the `final` Dockerfile stage:

```sh
#!/bin/sh
set -e

if [ "$SSL_MODE" = "none" ]; then
    curl -fsS http://localhost/ -o /dev/null
else
    curl -fsSk https://localhost/ -o /dev/null
fi
```

Targets the container's own internal `localhost`, not `$APP_URL` — `APP_URL` reflects the externally-mapped host/port (e.g. `http://localhost:8080`), which doesn't necessarily match nginx's internal `:80`/`:443`, so curling it literally from inside the container would fail regardless of actual health. `-k` skips TLS verification for `selfsigned`/`custom`/`certbot` modes since this is an internal loopback check, not a security boundary. `curl` is already installed in the `php-base` stage, so no new package is needed.

Dockerfile addition:

```dockerfile
COPY docker/healthcheck.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/healthcheck.sh
...
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD ["/usr/local/bin/healthcheck.sh"]
```

`--start-period=30s` gives the certbot bootstrap step (first-run network round trip to Let's Encrypt) room before failed checks count toward the retry threshold.

## Non-goals

- No automatic detection of "public vs internal" domain — `SSL_MODE` is explicit, per the issue.
- No UI/application-level changes; `APP_URL`'s scheme is the operator's responsibility to set consistently with `SSL_MODE`.
- No DNS-01 challenge support for certbot (wildcard certs) — HTTP-01/webroot only.
- No automatic self-signed cert rotation/renewal — 10-year validity is treated as effectively permanent for this use case; regenerating means clearing the volume's `selfsigned/` subdirectory.

## Testing / verification plan

No Pest coverage applies — this is Docker/shell infrastructure, not PHP application code. Verification is a manual, documented smoke test instead:

- `docker build` succeeds with the new packages/files.
- `docker-compose up` with `SSL_MODE` unset → behavior identical to today (HTTP on `:80`, no `:443`).
- `SSL_MODE=selfsigned` → container starts, `:443` serves the app with a self-signed cert for `$APP_URL`'s host, `:80` redirects to `:443`, restarting the container reuses the same cert (no regeneration).
- `SSL_MODE=custom` with test `fullchain.pem`/`privkey.pem` dropped into the volume → served as-is; missing files → container fails to start with a clear error.
- `SSL_MODE=certbot` without `CERTBOT_EMAIL` → fails fast with a clear error. Full issuance against a real reachable domain isn't exercisable in this environment; validated instead via `certbot ... --dry-run` against the constructed command and a careful read-through of the bootstrap script logic.
- `docker inspect --format='{{json .State.Health}}'` shows `healthy` after `--start-period` in every `SSL_MODE`, including `selfsigned`/`custom` where the healthcheck's own TLS verification is skipped.
- `shellcheck` run against `entrypoint.sh`, `certbot-renew.sh`, and `healthcheck.sh` if available in the environment.
