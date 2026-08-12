# Anagrafica Clienti (Customer Registry) — Design

Data: 2026-08-11

## Obiettivo

Introdurre un'anagrafica clienti (`Customer`) con relativa gestione paesi (`Country`), CRUD completo backend + frontend, in un'app Laravel 13 / Inertia v3 / Vue 3 (starter kit ufficiale).

## 1. Livello dati

### Migrations

Ordine: `countries` prima di `customers` (FK).

**`countries`**
- `id` — uuid, primary key
- `name` — string, unique
- timestamps

**`customers`**
- `id` — uuid, primary key
- `name` — string, required
- `address` — string, nullable
- `zip` — string, nullable
- `city` — string, nullable
- `country_id` — uuid, nullable, FK → `countries.id`, `nullOnDelete()`
- `state` — string, nullable
- `email` — string, nullable
- `web` — string, nullable
- `phone` — string, nullable
- `nif` — string, nullable
- timestamps

Solo `name` è obbligatorio. Se una country viene eliminata, i customer collegati mantengono il record ma perdono il riferimento (`country_id` → null), non vengono cancellati a cascata.

### Modelli

- `App\Models\Country`: `HasUuids`, `HasFactory`, relazione `HasMany` verso `Customer`.
- `App\Models\Customer`: `HasUuids`, `HasFactory`, relazione `BelongsTo` verso `Country`.

Entrambi con `#[Fillable(...)]` esplicito (pattern già usato in `User`), PHPDoc `@property` per i campi, cast dove serve.

## 2. Backend

- Route resource protette da middleware `['auth', 'verified']`, in `routes/customers.php` e `routes/countries.php` (richiamati da `web.php`, come `settings.php`).
- `CustomerController` e `CountryController`: azioni resourceful standard (`index`, `create`, `store`, `edit`, `update`, `destroy`). Nessun `show` dedicato.
- Validazione via Form Request:
  - `StoreCustomerRequest` / `UpdateCustomerRequest`: `name` required|string; `email` nullable|email:rfc,dns; `web` nullable|url; `country_id` nullable|exists:countries,id; resto nullable|string.
  - `StoreCountryRequest` / `UpdateCountryRequest`: `name` required|string|unique (ignorando il record corrente in update).
- Nessuna Policy: unico controllo è l'autenticazione (app mono-tenant ad uso amministrativo).

## 3. Seeder Countries

- `CountrySeeder`: elenco standard ISO 3166-1 (~195 paesi, nome in italiano), inserito in bulk (`Country::query()->insert()`), richiamato da `DatabaseSeeder::run()`.

## 4. Frontend (Inertia + Vue)

Non esistono ancora pagine "risorsa" nello starter kit: si stabilisce il pattern seguendo le convenzioni esistenti (form in stile `settings/Profile.vue`, componenti da `resources/js/components/ui/*`).

- `resources/js/pages/customers/{Index,Create,Edit}.vue`
- `resources/js/pages/countries/{Index,Create,Edit}.vue`
- **Index**: tabella HTML con Tailwind (nessun componente `table` shadcn, non installato — niente nuove dipendenze senza approvazione) + paginazione Laravel (`paginate(15)`) + ricerca testuale su `name`/`email` via query string.
- **Create/Edit**: form con `Input`, `Label`, `Select` (per `country_id`), `Button` esistenti; `useForm` di Inertia; errori con `InputError`.
- Rotte generate via Wayfinder (`php artisan wayfinder:generate`) — frontend usa `customers.index()` ecc., niente URL hardcoded.
- Due nuove voci "Clienti" e "Paesi" in `mainNavItems` (`AppSidebar.vue`), icone `Users` / `Globe` da `@lucide/vue`.

## 5. Testing

Pest feature test:
- `CustomerTest`: CRUD felice completo + validazione (`name` required, `email`/`web` formato invalido, `country_id` inesistente rigettato) + autenticazione richiesta sulle rotte.
- `CountryTest`: stesso schema + `name` unique.
- `CustomerFactory`, `CountryFactory` per i dati di test.

Nessun test JS/browser dedicato: copertura delle pagine Vue tramite `assertInertia` nei feature test backend, in linea con il progetto.

## Note

Il repository non è sotto controllo Git (`Is a git repository: false`), quindi questo documento non viene committato.
