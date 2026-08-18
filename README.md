<p align="center">
  <img src="public/images/logo.png" alt="Biglins" width="360">
</p>

# Biglins

[![tests](https://github.com/simonecosci/biglins/actions/workflows/tests.yml/badge.svg)](https://github.com/simonecosci/biglins/actions/workflows/tests.yml)
[![docker-publish](https://github.com/simonecosci/biglins/actions/workflows/docker-publish.yml/badge.svg)](https://github.com/simonecosci/biglins/actions/workflows/docker-publish.yml)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Invoicing app for freelancers and sole proprietors: customer records, invoices with line items/VAT, credit notes to reverse a previous invoice, automatic sequential numbering, PDF preview and generation, invoice duplication, a renewal schedule for recurring services (domains, hosting, maintenance) with renew/cancel actions from the dashboard, a year-to-date revenue summary, and estimates (quotes) with a markdown proposal, file attachments, PDF/ZIP export, and one-click conversion into an invoice once accepted.

## Stack

- **Backend**: Laravel 13 (PHP 8.3+), Inertia.js v3, Laravel Fortify (auth, 2FA, passkeys), Laravel Sanctum + Passport (API tokens / MCP OAuth)
- **Frontend**: Vue 3 + TypeScript, Inertia Vue, Tailwind CSS v4, reka-ui
- **Typed routing**: Laravel Wayfinder (`resources/js/actions`, `resources/js/routes`, generated — not versioned)
- **PDF**: barryvdh/laravel-dompdf
- **Testing**: Pest 5 / PHPUnit 13
- **Database**: SQLite by default (see [DATABASE.md](DATABASE.md))

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan passport:keys
npm run build   # or npm run dev in another terminal
```

Start the dev environment (server, queue worker, Vite) with a single command:

```bash
composer run dev
```

The app runs at `http://localhost` (see `APP_URL` in `.env`).

## Docker setup

```bash
docker compose up -d --build
docker compose run --rm app php artisan migrate --force   # first run only
```

The app runs at `http://localhost` (port configurable via `APP_PORT` in `.env`). See [Dockerfile](Dockerfile) and [docker-compose.yml](docker-compose.yml) for image details: PHP-FPM + nginx + queue worker on Debian trixie, managed by supervisord.

### HTTPS

Set `SSL_MODE` in `.env` to terminate TLS at nginx (default `none` — HTTP only, unchanged):

| `SSL_MODE`       | Behavior                                                                                                                                                     |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `none` (default) | HTTP only, on the HTTP port.                                                                                                                                 |
| `selfsigned`     | Self-signed certificate generated for `APP_URL`'s host, persisted in the `certs` volume. The HTTP port redirects to the HTTPS port.                          |
| `certbot`        | Let's Encrypt certificate via HTTP-01, auto-renewed daily. Requires `APP_URL` to be a publicly reachable domain and `CERTBOT_EMAIL` to be set.               |
| `custom`         | Bring your own certificate: place `fullchain.pem`/`privkey.pem` issued by your own CA into the `certs` volume under `custom/` before starting the container. |

HTTPS is served on `443` (host) → `:8443` (container).

`certbot` and `custom` require a real `APP_URL` set in `.env` (not the default `http://localhost`), since the certificate domain is derived from its host.

### Running rootless

The container always listens internally on the unprivileged ports `8080`/`8443`
(mapped to host `80`/`443` — or whatever you choose — via `docker run -p` /
`docker-compose.yml`), so no `CAP_NET_BIND_SERVICE` is ever required and the
port mapping is identical whether the container runs as root or as an
arbitrary non-root UID.

The image also auto-detects whether it's running as root or as an arbitrary
non-root UID and adjusts itself accordingly — no build flag needed. When
started as a non-root UID:

- Ownership fixups (`chown`) are skipped in favor of group-writable
  permissions baked into the image at build time, following the OpenShift
  arbitrary-UID convention: the container's runtime GID must be **0** (as a
  primary or supplementary group), either supplementary or primary.
- A synthetic `/etc/passwd` entry is generated via `nss_wrapper` for UIDs
  that don't already have one, so `getpwuid()`-dependent tooling (OpenSSL,
  Certbot, some PHP extensions) keeps working.

Example with plain `docker run`:

```bash
docker run --user 1000:0 -p 80:8080 -p 443:8443 --env-file .env simonecosci/biglins
```

On Kubernetes/OpenShift, set `securityContext.runAsUser` to any UID and
`securityContext.runAsGroup: 0` (OpenShift's default restricted SCC does
this automatically, so most deployments need no `securityContext` at all).

**Caveat:** for `storage`/`database`/`certs` backed by a fresh Docker/Podman
named volume, the image's baked permissions carry over automatically on
first mount. Kubernetes PersistentVolumeClaims don't get this treatment —
if your storage provisioner doesn't already grant group-0 write access,
set a matching `fsGroup` in the pod's `securityContext`.

## Desktop app (NativePHP)

Biglins can also run as a standalone desktop app (Windows/macOS/Linux) via
[NativePHP](https://nativephp.com/), independent of the Docker/web
deployment above. Each installation is single-user and keeps its own local
SQLite database — no server required.

```bash
composer require nativephp/desktop   # already in composer.json — only needed once
php artisan native:install           # already run for this repo; re-run after upgrading nativephp/desktop
composer native:dev                  # launch the desktop app in dev mode (Vite + native:run together)
```

`native:run` alone only starts the Electron/PHP side; it expects assets
from a Vite dev server that isn't running unless you start it too, which
shows up as `ERR_CONNECTION_REFUSED` in the app window's DevTools console
for `app.ts`/`app.css`/every page component. `composer native:dev` runs
`npm run dev` and `php artisan native:run` together (via `concurrently`) so
this isn't an issue.

If you hit `The route _native/api/events could not be found` in
`storage/logs/laravel.log` and the window opens but the page never loads,
your route/config cache predates installing `nativephp/desktop` — run
`php artisan optimize:clear` to rebuild it.

NativePHP automatically creates and migrates a local SQLite database inside
the OS's per-user application-data directory on first run, and does the
same for anything stored on Laravel's `local` filesystem disk — no manual
database configuration needed.

### Building installers

```bash
php artisan native:build win     # produces nativephp/electron/dist/win-unpacked/
php artisan native:build mac     # produces a macOS .dmg — requires an Apple ID + team ID
                                  # and notarization, or other Macs will refuse to open it
php artisan native:build linux   # produces a Linux AppImage and .deb package
```

Cross-compilation has limits: building Windows binaries from Linux needs
Wine with 32-bit support, and macOS builds must be signed/notarized on
Apple hardware to run on other Macs. `mac`/`linux` builds are not exercised
by this project's CI.

**Windows note:** on a non-elevated account without Developer Mode enabled,
`native:build win` reliably produces the runnable `win-unpacked/biglins.exe`
folder, but the NSIS installer step needs Windows'
`SeCreateSymbolicLinkPrivilege` to unpack its code-signing tooling and
silently doesn't produce an installer `.exe` without it. Enable Developer
Mode (Settings → Privacy & security → For developers) or build as an
Administrator to get a proper installer.

Auto-update is not configured; distribute new versions as new installers.

**Never build a desktop release from a `.env` carrying the web deployment's
real `APP_KEY` or other production secrets that can't be stripped.**
`APP_KEY` specifically ships inside the bundle since the app needs it at
runtime, so a desktop release must be built from its own freshly generated
key, not a copy of the production web `.env`.

## MCP server (AI agent integration)

Biglins ships an [MCP](https://modelcontextprotocol.io/) server so AI agents (Claude, etc.) can list/create customers, list/create estimations, list/create invoices, and send an invoice or estimation by email — see `app/Mcp/Servers/BiglinsServer.php` and `app/Mcp/Tools/` for the 8 tools. Every tool takes an explicit `company_id` (there's no per-user tenancy in this app: any authenticated user/token can act on any company in the database). Registered in [routes/ai.php](routes/ai.php), with two transports depending on how you run Biglins:

### Desktop app (local, no auth)

The desktop build runs single-user on your own machine, so the local transport needs no token — whoever can run the command already has full access to your local database. Point your MCP client at:

```json
{
  "mcpServers": {
    "biglins": {
      "command": "php",
      "args": ["artisan", "mcp:start", "biglins"],
      "cwd": "/path/to/biglins"
    }
  }
}
```

### Docker / web deployment (HTTP + API token)

An HTTP endpoint is exposed at `/mcp/biglins`, protected by a [Sanctum](https://laravel.com/docs/sanctum) personal access token — required since a Docker deployment can be reachable over the internet.

1. Log in, go to **Settings → API Tokens**, and create a token (the value is shown once — copy it immediately, only its hash is stored).
2. Point your MCP client at the endpoint with that token as a bearer token:

```json
{
  "mcpServers": {
    "biglins": {
      "url": "https://your-domain.example/mcp/biglins",
      "headers": {
        "Authorization": "Bearer <your-api-token>"
      }
    }
  }
}
```

Revoke a token any time from the same Settings page — revocation is immediate.

### Claude Desktop (OAuth)

The bearer-token setup above works for clients that let you set custom
headers (e.g. Claude Code), but Claude Desktop's built-in "Add custom
connector" dialog only supports OAuth — it doesn't have a field for a raw
token. For that client, point it at the HTTP endpoint instead:

1. In Claude Desktop, add a custom connector with URL `https://your-domain.example/mcp/biglins`.
2. Leave the OAuth Client ID/Secret fields blank — Claude registers its own client automatically via [dynamic client registration](https://datatracker.ietf.org/doc/html/rfc7591).
3. Claude opens a browser to log in and approve access; tokens are then managed by Claude, no manual copying needed.

This is backed by [Laravel Passport](https://laravel.com/docs/passport) as the
OAuth2 authorization server (`laravel/passport`), configured alongside
Sanctum — the `/mcp/biglins` endpoint accepts either a Sanctum API token or a
Passport OAuth token. Manage authorized OAuth clients like any other Passport
installation (`php artisan passport:client --list`, `passport:purge` for
expired/revoked tokens).

## Useful commands

| Command                           | Description                                    |
| --------------------------------- | ---------------------------------------------- |
| `php artisan test --compact`      | Run the Pest test suite                        |
| `composer lint`                   | Format PHP code with Pint                      |
| `composer lint:check`             | Check code style without modifying files       |
| `composer types:check`            | Static analysis with Larastan/PHPStan          |
| `composer ci:check`               | Lint + format + types + test (what runs in CI) |
| `npm run lint` / `lint:check`     | ESLint on `resources/`                         |
| `npm run format` / `format:check` | Prettier on `resources/`                       |
| `npm run types:check`             | TypeScript type-check (`vue-tsc`)              |

## Project structure

- `app/Http/Controllers` — Inertia controllers (`CustomerController`, `InvoiceController`, `SubscriptionController`, `DashboardController`, `CountryController`, ...)
- `app/Models` — `Customer`, `Invoice`, `InvoiceRow`, `Country`, `User` (UUID primary keys except `User`)
- `app/Enums` — `SubscriptionStatus`, `ExpirationUrgency`, `ProductType`, `InvoiceType` (`invoice` / `credit_note`)
- `app/Actions/Fortify` — authentication actions (Fortify)
- `resources/js/pages` — Inertia/Vue pages
- `public/images/companies` — logos of issuing companies uploaded via the UI (**not versioned**, see `.gitignore`)
- `docs/superpowers/` — feature specs and development plans (internal workflow, not part of the app)

## Release

The version number shown in the UI (sidebar) is read from `"version"` in [composer.json](composer.json); the Docker image tag is instead derived from the pushed Git tag (see [docker-publish.yml](.github/workflows/docker-publish.yml)). To keep them in sync, on every release:

```bash
# 1. Bump "version" in composer.json (e.g. 1.1.0)
# 2. Commit the change
git add composer.json
git commit -m "chore: bump version to 1.1.0"
git push origin main

# 3. Create and push the matching Git tag (with the v prefix)
git tag v1.1.0
git push origin v1.1.0
```

Pushing the `vX.Y.Z` tag triggers `docker-publish.yml`, which publishes `simonecosci/biglins:X.Y.Z`, `simonecosci/biglins:X.Y` to Docker Hub and updates `simonecosci/biglins:latest`.

## Documentation

- [AGENTS.md](AGENTS.md) — conventions for anyone (or anything) contributing to the code
- [DATABASE.md](DATABASE.md) — database schema
- [CONTRIBUTING.md](CONTRIBUTING.md) — how to contribute
- [SECURITY.md](SECURITY.md) — how to report a vulnerability
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) — community guidelines
