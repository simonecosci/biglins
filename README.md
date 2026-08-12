# Biglins

Applicazione di fatturazione per liberi professionisti: anagrafica clienti, emissione fatture con righe/IVA, numerazione progressiva automatica, anteprima e generazione PDF, duplicazione fattura.

## Stack

- **Backend**: Laravel 13 (PHP 8.3+), Inertia.js v3, Laravel Fortify (auth, 2FA, passkey)
- **Frontend**: Vue 3 + TypeScript, Inertia Vue, Tailwind CSS v4, reka-ui
- **Routing tipizzato**: Laravel Wayfinder (`resources/js/actions`, `resources/js/routes`, generati — non versionati)
- **PDF**: barryvdh/laravel-dompdf
- **Test**: Pest 5 / PHPUnit 13
- **DB**: SQLite di default (vedi [DATABASE.md](DATABASE.md))

## Setup locale

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build   # oppure npm run dev in un altro terminale
```

Avvio ambiente di sviluppo (server, queue worker, Vite) in un solo comando:

```bash
composer run dev
```

L'app risponde su `http://localhost:8080` (vedi `APP_URL` in `.env`).

## Setup con Docker

```bash
docker compose up -d --build
docker compose run --rm app php artisan migrate --force   # solo al primo avvio
```

L'app risponde su `http://localhost:8080` (porta configurabile con `APP_PORT` in `.env`). Dettagli sull'immagine in [Dockerfile](Dockerfile) e [docker-compose.yml](docker-compose.yml): PHP-FPM + nginx + queue worker su Debian trixie, gestiti da supervisord.

## Comandi utili

| Comando | Descrizione |
|---|---|
| `php artisan test --compact` | Esegue la test suite Pest |
| `composer lint` | Formatta il codice PHP con Pint |
| `composer lint:check` | Verifica lo stile senza modificare i file |
| `composer types:check` | Analisi statica con Larastan/PHPStan |
| `composer ci:check` | Lint + format + types + test (quello che gira in CI) |
| `npm run lint` / `lint:check` | ESLint su `resources/` |
| `npm run format` / `format:check` | Prettier su `resources/` |
| `npm run types:check` | Type-check TypeScript (`vue-tsc`) |

## Struttura del progetto

- `app/Http/Controllers` — controller Inertia (`CustomerController`, `InvoiceController`, `CountryController`, ...)
- `app/Models` — `Customer`, `Invoice`, `InvoiceRow`, `Country`, `User` (chiavi primarie UUID tranne `User`)
- `app/Actions/Fortify` — azioni di autenticazione (Fortify)
- `resources/js/pages` — pagine Inertia/Vue
- `public/images/companies` — loghi delle aziende emittenti caricati dalla UI (**non versionati**, vedi `.gitignore`)
- `docs/superpowers/` — spec e piani di sviluppo delle feature (workflow interno, non fa parte dell'app)

## Documentazione

- [AGENTS.md](AGENTS.md) — convenzioni per chi (o cosa) contribuisce al codice
- [DATABASE.md](DATABASE.md) — schema del database
