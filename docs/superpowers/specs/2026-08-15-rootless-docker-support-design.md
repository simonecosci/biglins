# Rootless Docker Support — Design

GitHub issue: [#16](https://github.com/simonecosci/biglins/issues/16)

## Problem

The application's Docker image currently only runs correctly as root:
supervisord is hardcoded to `user=root`, nginx and php-fpm bind privileged
ports 80/443, php-fpm's pool config setuids to `www-data`, and the entrypoint
relies on `chown` to fix up ownership of volumes on every start. Container
platforms that enforce non-root execution with an arbitrary, unpredictable
UID (Podman default rootless runs, Kubernetes `runAsNonRoot`/`runAsUser`
policies, OpenShift/OKD's arbitrary-UID SCC) can't run this image as-is.

## Goal

Make the existing image runnable both as root (current `docker-compose.yml`
flow, unchanged) and as an arbitrary non-root UID sharing GID 0 (the
OpenShift/Bitnami/Red Hat UBI convention), auto-detected at container start,
with no build args or separate image variant.

## Non-goals

- Supporting arbitrary UID **without** GID 0 (e.g. `--user 1234:1234` with a
  non-zero, non-shared group). Documented as unsupported; the standard
  workaround is `--user UID:0`.
- Changing the default behavior of the existing `docker-compose.yml` /
  root-mode flow in any way.
- Solving PVC/storage-class-level permission quirks outside the container's
  control (documented as a caveat).

## Design

### Detection

`entrypoint.sh` computes `IS_ROOT` once via `[ "$(id -u)" = "0" ]` near the
top of the script and branches on it for every step below. The root branch
is byte-for-byte what the script does today.

### Ports

Non-root can't bind ports <1024 (no `CAP_NET_BIND_SERVICE`). The nginx site
configs (`app.conf`, `app-ssl-http.conf`, `app-ssl-https.conf`) are rendered
through `envsubst` at entrypoint time with `HTTP_PORT`/`HTTPS_PORT`
variables (default `80`/`443`). The entrypoint sets `HTTP_PORT=8080
HTTPS_PORT=8443` before rendering when non-root, and leaves the defaults
otherwise. `docker/healthcheck.sh` reads the same rendered port instead of
hardcoding `localhost/`. `docker-compose.yml`'s existing
`${APP_PORT:-8080}:80` root-mode mapping is untouched — external port
mapping for non-root deployments (k8s Service/Ingress, OpenShift Route,
`podman run -p`) is the orchestrator's responsibility, same as it already is
for the Docker Desktop host-port mapping today.

### Filesystem permissions

At build time (`Dockerfile`), after creating the runtime directories
(`storage/...`, `bootstrap/cache`, `/run/php`, `/var/lib/nginx`,
`/var/log/nginx`, `/run`), apply the OpenShift arbitrary-UID convention:

```
chgrp -R 0 <dirs> && chmod -R g=u <dirs>
```

Any UID sharing GID 0 (supplementary or primary — OpenShift's arbitrary-UID
SCC sets this automatically; so does `docker run --user 1000:0`) then has
the same read/write access as the owning user.

At runtime, `entrypoint.sh` only runs its existing `chown -R www-data:www-data
...` calls when `IS_ROOT`. The non-root branch skips them and relies on the
baked-in group permissions. This also covers first-mount of empty named
volumes: Docker/Podman copy the image directory's contents *and
permissions* into a fresh named volume the first time it's mounted, so the
GID-0 permissions baked at build time carry over.

### php-fpm

`docker/php/www.conf`'s `user = www-data` / `group = www-data` directives
make php-fpm's master process refuse to start when it isn't root. Add a
second pool file, `docker/php/www-rootless.conf`, identical except without
those two directives (so php-fpm runs the pool as whatever UID launched the
master). The entrypoint copies the appropriate file into
`/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf` before exec, mirroring the
existing SSL site-swap pattern in the same script. The php-fpm ↔ nginx link
stays a unix socket at `/run/php/php-fpm.sock` in both modes — nginx and
php-fpm run as the same UID, so there's no cross-user socket permission
issue, and `/run/php` already gets GID-0 treatment above.

### nginx

The top-level `user www-data;` directive is a no-op (nginx emits a warning
and ignores it) when the master process isn't root, so it needs no
branching. Only the rendered listen ports and the pre-baked writable runtime
directories (pid file, logs, client body temp path) matter.

### supervisord

Remove the hardcoded `user=root` line from `supervisord.conf`. It's a no-op
when actually running as root (nothing to switch to) and a hard failure
(fatal setuid error) when non-root. No templating needed.

### nss_wrapper

Install `libnss-wrapper` in the final image stage. In the non-root branch,
the entrypoint checks `getent passwd "$(id -u)"`; if it's empty (no
`/etc/passwd` entry for this UID — the normal case for an arbitrary
OpenShift-assigned UID), it generates `/tmp/passwd` and `/tmp/group` (copies
of `/etc/passwd`/`/etc/group` plus a synthesized entry for the current
UID:GID, home directory `/tmp`), then exports `NSS_WRAPPER_PASSWD`,
`NSS_WRAPPER_GROUP`, and `LD_PRELOAD=libnss_wrapper.so` before `exec "$@"`.
Every child process (supervisord, nginx, php-fpm, artisan, openssl,
certbot) inherits these and gets a resolvable `getpwuid()`/`whoami`.

### SSL / certbot

No special-casing. `SSL_MODE=certbot`'s HTTP-01 challenge works in both
modes as long as the orchestrator maps external 80/443 to whatever port the
container is actually listening on internally (8080 in non-root mode) —
exactly the same requirement that already exists for the root-mode
`docker-compose.yml` flow today.

### Documentation

Add a "Running rootless" section to `README.md` with a worked example
(OpenShift/K8s `securityContext` with an arbitrary `runAsUser` and GID 0, or
`docker run --user 1000:0`), and note the GID-0 requirement and the
PVC-permissions caveat.

## Testing

This is infrastructure, not application code — no Pest coverage applies.
Verification is manual:

1. `docker build` succeeds unchanged.
2. `docker compose up` (root mode) still serves correctly on the existing
   ports — regression check that nothing in the entrypoint/config changes
   broke the default flow.
3. `docker run --user 1000:0 -p 8080:8080 -p 8443:8443 <image>` starts
   cleanly, serves the app on 8080, and logs show no permission errors from
   nginx, php-fpm, or the entrypoint's directory setup.
