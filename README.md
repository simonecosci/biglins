<p align="center">
  <img src="public/images/logo.png" alt="Biglins" width="360">
</p>

# Biglins

[![tests](https://github.com/simonecosci/biglins/actions/workflows/tests.yml/badge.svg)](https://github.com/simonecosci/biglins/actions/workflows/tests.yml)
[![docker-publish](https://github.com/simonecosci/biglins/actions/workflows/docker-publish.yml/badge.svg)](https://github.com/simonecosci/biglins/actions/workflows/docker-publish.yml)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Invoicing app for freelancers and sole proprietors: customer records, invoices with line items/VAT, automatic sequential numbering, PDF preview and generation, invoice duplication, a renewal schedule for recurring services (domains, hosting, maintenance) with renew/cancel actions from the dashboard, and a year-to-date revenue summary.

## Stack

- **Backend**: Laravel 13 (PHP 8.3+), Inertia.js v3, Laravel Fortify (auth, 2FA, passkeys)
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
npm run build   # or npm run dev in another terminal
```

Start the dev environment (server, queue worker, Vite) with a single command:

```bash
composer run dev
```

The app runs at `http://localhost:8080` (see `APP_URL` in `.env`).

## Docker setup

```bash
docker compose up -d --build
docker compose run --rm app php artisan migrate --force   # first run only
```

The app runs at `http://localhost:8080` (port configurable via `APP_PORT` in `.env`). See [Dockerfile](Dockerfile) and [docker-compose.yml](docker-compose.yml) for image details: PHP-FPM + nginx + queue worker on Debian trixie, managed by supervisord.

## Useful commands

| Command | Description |
|---|---|
| `php artisan test --compact` | Run the Pest test suite |
| `composer lint` | Format PHP code with Pint |
| `composer lint:check` | Check code style without modifying files |
| `composer types:check` | Static analysis with Larastan/PHPStan |
| `composer ci:check` | Lint + format + types + test (what runs in CI) |
| `npm run lint` / `lint:check` | ESLint on `resources/` |
| `npm run format` / `format:check` | Prettier on `resources/` |
| `npm run types:check` | TypeScript type-check (`vue-tsc`) |

## Project structure

- `app/Http/Controllers` — Inertia controllers (`CustomerController`, `InvoiceController`, `SubscriptionController`, `DashboardController`, `CountryController`, ...)
- `app/Models` — `Customer`, `Invoice`, `InvoiceRow`, `Country`, `User` (UUID primary keys except `User`)
- `app/Enums` — `SubscriptionStatus`, `ExpirationUrgency`, `ProductType`
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
