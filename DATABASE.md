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

### `companies`
UUID primary key. The invoicing entities (issuers). Belongs to `countries` (`country_id`, nullable, `nullOnDelete`). Managed through the `/companies` UI — don't seed real data.

| Column | Notes |
|---|---|
| `name` | required |
| `tax_id`, `address`, `zip`, `city`, `email`, `phone`, `iban` | all nullable |
| `country_id` | FK → `countries.id`, nullable |
| `logo` | nullable path relative to `public/` (`images/companies/{id}.{ext}`), file not versioned |
| `is_default` | boolean, default `false` — at most one company is the default; it's the fallback `App\Support\CurrentCompany::resolve()` uses when no company is selected in the session yet |

### `invoices`
UUID primary key. Belongs to `customers` (`customer_id`, `restrictOnDelete` — a customer with invoices can't be deleted) and to `companies` (`company_id`, `restrictOnDelete`).

| Column | Notes |
|---|---|
| `number` | unique per company (`unique(company_id, number)`), auto-generated as `{year}-{4-digit sequence}` (`Invoice::nextNumber(string $companyId, ?string $year = null)`), resets per year and per company |
| `invoice_date` | date |
| `paid` | boolean, default `false` |
| `customer_id` | FK → `customers.id` |
| `company_id` | FK → `companies.id` — the issuer whose data renders in the PDF header |
| `note` | nullable text |
| `language` | 2-letter code, default `es` — drives the PDF locale |

Computed (not columns, derived from `rows` in the `Invoice` model): `subtotal`, `vat_total`, `total`.

### `invoice_rows`
UUID primary key. Belongs to `invoices` (`invoice_id`, `cascadeOnDelete` — deleting an invoice deletes its rows).

| Column | Notes |
|---|---|
| `description` | required |
| `quantity` | `decimal(10,2)`, default `1.00`, multiplied against `price` |
| `price` | `decimal(10,2)` |
| `vat_rate` | `decimal(5,2)`, percentage applied to `price * quantity` |
| `expiration_date` | nullable date — set only when the row is a recurring/renewable service (domain, hosting, maintenance, ...); `NULL` means a one-off row |
| `subscription_status` | string, cast to `App\Enums\SubscriptionStatus` (`active` \| `cancelled`), default `active` — only meaningful when `expiration_date` is set |

`InvoiceRow::scopeSubscriptions()` filters to rows with a non-null `expiration_date` and `subscription_status = active`; this is what powers the Dashboard's scadenziario widget (see the wiki's [Dashboard](https://github.com/simonecosci/biglins/wiki/Dashboard) page).

### `products`
UUID primary key. Belongs to `companies` (`company_id`, required, `restrictOnDelete` — a company with products can't be deleted).

| Column | Notes |
|---|---|
| `code` | nullable, unique globally (not per-company) |
| `type` | string, cast to `App\Enums\ProductType` |
| `description` | required |
| `price` | `decimal(10,2)` |
| `company_id` | FK → `companies.id`, **not nullable** |

## Factories & seeding

Models use `HasFactory` (`database/factories/`). Prefer factories over manual `Model::create()` in tests — check for existing factory states before adding new ones.
