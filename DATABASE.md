# Database

Default connection: **SQLite** (`DB_CONNECTION=sqlite`, file at `database/database.sqlite`). MySQL/PostgreSQL work too via the standard Laravel `DB_*` env vars in `config/database.php` — nothing in the schema is SQLite-specific.

Session, cache and queue all use the `database` driver by default (`SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` in `.env`), so `sessions`, `cache`/`cache_locks` and `jobs`/`job_batches`/`failed_jobs` are populated by the framework, not application code.

Migrations live in `database/migrations/`. Run with `php artisan migrate` (add `--force` outside `local`/`testing`).

## Schema

### `users`
Auth accounts (Fortify). Auto-increment `id`.

| Column | Notes |
|---|---|
| `id`, `name`, `email` (unique), `email_verified_at`, `password`, `remember_token`, timestamps | standard Laravel auth |
| `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` | Fortify 2FA (added in `2025_08_14_170933_...`) |

Related: `password_reset_tokens`, `sessions` (both created alongside `users`).

### `passkeys`
WebAuthn passkeys, one-to-many from `users` (`user_id`, cascade on delete). Stores `credential_id` (unique), `credential` (JSON), `last_used_at`.

### `countries`
Lookup table: `id` (UUID), `name` (unique). Seeded/managed via `CountryController`.

### `customers`
UUID primary key. Belongs to `countries` (`country_id`, nullable, `nullOnDelete`).

| Column | Notes |
|---|---|
| `name` | required |
| `address`, `zip`, `city`, `state`, `email`, `web`, `phone`, `nif` | all nullable |
| `country_id` | FK → `countries.id`, nullable |

### `invoices`
UUID primary key. Belongs to `customers` (`customer_id`, `restrictOnDelete` — a customer with invoices can't be deleted).

| Column | Notes |
|---|---|
| `number` | unique, auto-generated as `{year}-{4-digit sequence}` (`Invoice::nextNumber()`), resets per year |
| `invoice_date` | date |
| `paid` | boolean, default `false` |
| `customer_id` | FK → `customers.id` |
| `note` | nullable text |
| `language` | 2-letter code, default `es` — drives the PDF locale |

Computed (not columns, derived from `rows` in the `Invoice` model): `subtotal`, `vat_total`, `total`.

### `invoice_rows`
UUID primary key. Belongs to `invoices` (`invoice_id`, `cascadeOnDelete` — deleting an invoice deletes its rows).

| Column | Notes |
|---|---|
| `description` | required |
| `price` | `decimal(10,2)` |
| `vat_rate` | `decimal(5,2)`, percentage applied to `price` |

## Not in the database

`config/company.php` holds the invoicing entity's own data (name, tax id, address, IBAN, logo) used to render the PDF header — it's a gitignored config file, not a table, since it's environment-specific and contains real personal/financial data. Copy `config/company.php` from a teammate or recreate it locally before generating PDFs; see `.gitignore`.

## Factories & seeding

Models use `HasFactory` (`database/factories/`). Prefer factories over manual `Model::create()` in tests — check for existing factory states before adding new ones.
