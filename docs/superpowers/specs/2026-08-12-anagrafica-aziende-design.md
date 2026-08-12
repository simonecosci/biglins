# Anagrafica Aziende (Company Registry) — Design

Data: 2026-08-12

## Obiettivo

Sostituire `config/company.php` (dati fissi dell'azienda emittente, unica) con un'anagrafica `Company` su database, CRUD completo backend + frontend, con possibilità di avere più aziende emittenti. Ogni fattura (`Invoice`) viene collegata a una `Company` tramite `company_id`, selezionabile in creazione ed edit.

## 1. Livello dati

### Migrations

**`companies`** (nuova tabella, con `logo` come path relativo caricato via upload, non più da config)
- `id` — uuid, primary key
- `name` — string, required
- `tax_id` — string, nullable
- `address` — string, nullable
- `zip` — string, nullable
- `city` — string, nullable
- `country_id` — uuid, nullable, FK → `countries.id`, `nullOnDelete()`
- `email` — string, nullable
- `phone` — string, nullable
- `iban` — string, nullable
- `logo` — string, nullable (path relativo tipo `images/companies/{id}.png`)
- `is_default` — boolean, default `false`
- timestamps

Solo `name` è obbligatorio, stesso principio di `Customer`.

**Nuova migration additiva su `invoices`**: aggiunge `company_id` — uuid, FK → `companies.id`, `constrained()->restrictOnDelete()` (stesso pattern già usato per `customer_id`), **non nullable**. Non serve backfill: il DB di sviluppo viene svuotato (`migrate:fresh`), non siamo in produzione.

### Modelli

- `App\Models\Company`: `HasUuids`, `HasFactory`, `#[Fillable(['name', 'tax_id', 'address', 'zip', 'city', 'country_id', 'email', 'phone', 'iban', 'logo', 'is_default'])]`, relazione `BelongsTo` verso `Country`, `HasMany` verso `Invoice`. PHPDoc `@property` per i campi.
- `App\Models\Invoice`: aggiunta `company_id` a `#[Fillable]`, relazione `belongsTo(Company::class)`.

### Regola "azienda predefinita"

- Una sola `Company` può avere `is_default = true`.
- Nel controller, quando si crea/aggiorna una company con `is_default = true`, in transazione si azzera il flag sulle altre.
- La prima company creata nel sistema diventa `is_default` automaticamente, indipendentemente dal valore inviato dal form.

### Eliminazione bloccata se in uso

- `CompanyController::destroy`: se `$company->invoices()->exists()`, si rifiuta l'eliminazione con un messaggio di errore (flash), niente cancellazione.
- Il vincolo FK `restrictOnDelete()` resta come rete di sicurezza a livello DB.

## 2. Upload logo

- Campo opzionale nel form company, validato come immagine (`jpg,jpeg,png,svg,webp`, max 2MB).
- Salvato **direttamente in `public/images/companies/{company_id}.{ext}`** (non nel disk `storage`), per restare coerente con l'attuale convenzione (`config('company.logo')` risolto via `public_path()` nel template PDF, senza bisogno di symlink).
- In `companies.logo` viene salvato il path relativo (es. `images/companies/<uuid>.png`).
- Sostituendo il logo, il file precedente viene eliminato dal filesystem.
- È possibile rimuovere il logo (checkbox/azione "remove logo" nel form update) senza doverne caricare uno nuovo.

## 3. Backend

- `CompanyController`: azioni resourceful standard (`index`, `create`, `store`, `edit`, `update`, `destroy`), nessun `show`, stesso schema di `CustomerController`.
- Route protette da `['auth', 'verified']`, in `routes/companies.php`, richiamate da `web.php`.
- `StoreCompanyRequest` / `UpdateCompanyRequest`: `name` required|string; `email` nullable|email; `country_id` nullable|exists:countries,id; `logo` nullable|image|mimes:jpg,jpeg,png,svg,webp|max:2048; `is_default` boolean; resto nullable|string.
- `InvoiceController`:
  - `create()`/`edit()` passano anche `companies` (`id`, `name`, `logo`) e l'id della company di default.
  - `create()` include `company_id` nel payload `duplicate` quando si duplica una fattura esistente.
  - `StoreInvoiceRequest`/`UpdateInvoiceRequest`: aggiunta regola `'company_id' => ['required', 'uuid', 'exists:companies,id']`.
- `resources/views/invoices/template.blade.php`: tutte le chiamate `config('company.*')` sostituite con `$invoice->company->*` (incluso `$invoice->company->country?->name` se serve mostrare il paese); `preview()`/`pdf()` in `InvoiceController` eager-loadano `company.country` oltre a `customer.country`.
- Rimozione di `config/company.php` e `config/company.php.example` una volta che nulla li referenzia più.

## 4. Frontend (Inertia + Vue)

- `resources/js/pages/companies/{Index,Create,Edit}.vue`, stesso pattern di `customers/*` (Form/`useForm`, `Input`, `Label`, `Select` per `country_id`, `Button`, `InputError`).
- Nuovo file input per il logo (non esiste ancora un pattern di upload nell'app: si introduce un semplice `<input type="file">` stilizzato coerente col resto della UI) + preview del logo corrente in edit + opzione per rimuoverlo.
- Checkbox `is_default`.
- `invoices/Create.vue` e `invoices/Edit.vue`: nuovo `Select` per `company_id`, preselezionato con la company di default (in creazione) o con quella della fattura sorgente (in duplicazione).
- Nuova voce "Companies" in `mainNavItems` (`AppSidebar.vue`), icona `Building2` da `@lucide/vue`.
- Rotte generate via Wayfinder.

## 5. Testing

- `CompanyFactory`.
- `CompanyTest` (Pest, feature): CRUD felice completo; validazione (`name` required, `email` formato invalido, `country_id` inesistente, `logo` non immagine/troppo grande rigettati); upload/sostituzione/rimozione logo (verifica file su filesystem); esclusività `is_default` (impostarne una nuova azzera le altre); prima company creata diventa default automaticamente; eliminazione bloccata se la company ha fatture collegate; autenticazione richiesta sulle rotte.
- `InvoiceTest` / `InvoiceTemplateTest`: aggiornati per creare/passare una `Company` (via factory) ovunque venga creata o postata una fattura; le asserzioni sui dati "azienda" nel template passano dai valori di config a quelli della company generata dalla factory.

## 6. Wiki

- La documentazione vive nel repo GitHub Wiki separato (`github.com/simonecosci/biglins.wiki.git`, non incluso in questo checkout).
- Si clona in una directory di scratch, si riscrivono le pagine che oggi documentano `config/company.php` per descrivere invece l'anagrafica Companies (CRUD, selezione per fattura, upload logo, azienda di default), si committa e pusha.

## Note

Il DB di sviluppo verrà svuotato (`migrate:fresh`) prima di applicare le nuove migration: nessuna migrazione dati dalle fatture esistenti né da `config/company.php` è necessaria.
