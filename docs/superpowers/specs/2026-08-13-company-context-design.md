# Company Context (selezione company globale in header) — Design

Data: 2026-08-13

## Obiettivo

Spostare la selezione della "company operativa" dal form delle fatture a un selettore globale nell'header. La company selezionata in un dato momento (salvata in sessione, non per-utente) determina:
- la company assegnata automaticamente a fatture create/duplicate;
- la company assegnata automaticamente ai prodotti creati;
- il filtro delle liste fatture e prodotti (si vedono solo i record della company corrente);
- l'anteprima del prossimo numero fattura, che diventa una sequenza per company+anno invece che globale.

## 1. Livello dati

### Migrations

**Nuova migration additiva su `products`**: aggiunge `company_id` — uuid, FK → `companies.id`, `constrained()->restrictOnDelete()` (stesso pattern già usato per `invoices.company_id`), **non nullable**. Backfill: assegna a tutti i prodotti esistenti la company con `is_default = true` (dati di sviluppo, nessuna company multipla reale da gestire).

**Nuova migration su `invoices`**: sostituisce il vincolo `unique(number)` con un vincolo composito `unique(['company_id', 'number'])`, per permettere a company diverse di avere lo stesso numero nello stesso anno. Nessuna rinumerazione necessaria (dati di sviluppo).

### Modelli

- `App\Models\Product`: aggiunta `company_id` a `#[Fillable]`, relazione `belongsTo(Company::class)`.
- `App\Models\Company`: aggiunta relazione `hasMany(Product::class)`.
- `App\Models\Invoice::nextNumber()`: firma aggiornata per accettare `company_id` (oltre all'anno opzionale già esistente), query filtrata anche per `company_id`.

## 2. Contesto "company corrente" (sessione)

- Nuovo helper `App\Support\CurrentCompany` (classe con metodo statico `resolve(): Company`) che risolve la company corrente:
  1. se `session('current_company_id')` punta a una company esistente, usa quella;
  2. altrimenti fallback sulla company con `is_default = true`;
  3. altrimenti la prima company (`orderBy('name')->first()`).
- Nessuna colonna nuova su `users`: la selezione vive solo in sessione, si resetta implicitamente se la sessione scade o cambia browser (in quel caso si ricade sul fallback default/prima company).
- `App\Http\Middleware\HandleInertiaRequests`: aggiunge ai props condivisi `currentCompany` (oggetto `{id, name}`) e `companies` (lista completa `{id, name}`, ordinata per nome) per alimentare lo switcher in header — stesso pattern già usato per `auth`.

### Cambio company

- Nuova route `PUT /current-company` (middleware `auth`,`verified`), → `App\Http\Controllers\CurrentCompanyController@update`.
- Request validata inline o via Form Request dedicata: `company_id` `required|uuid|exists:companies,id`.
- Scrive `company_id` in `session('current_company_id')`, redirect `back()`.

## 3. Backend — Fatture

- `InvoiceController@create`: **rimuove** `companies`/`defaultCompanyId` dai props passati alla vista (non serve più scegliere la company nel form). Il numero successivo (`nextNumber`) viene calcolato con la company corrente dal contesto.
- `InvoiceController@store`: non legge più `company_id` dal payload del form; lo imposta lui stesso dalla company corrente (`CurrentCompany::resolve()->id`) prima del `create()`.
- `InvoiceController@index`: filtra sempre `where('company_id', CurrentCompany::resolve()->id)`.
- `InvoiceController@edit`/`update`/`destroy`: se `$invoice->company_id !== CurrentCompany::resolve()->id`, abort(403) — evita modifiche cross-company via URL diretta, dato che la company non è più scelta nel form.
- Duplicazione (`?duplicate={id}`): la nuova fattura precompilata usa comunque la company corrente (non quella della fattura sorgente) per il calcolo del prossimo numero.
- `StoreInvoiceRequest`/`UpdateInvoiceRequest`: rimossa la regola di validazione su `company_id` (non più un campo del form).

## 4. Backend — Prodotti

- `ProductController@store`: imposta `company_id` dalla company corrente, non da input utente.
- `ProductController@index`: filtra sempre `where('company_id', CurrentCompany::resolve()->id)` (inclusa la variante `wantsJson()` usata dal picker).
- `ProductController@edit`/`update`/`destroy`: stesso controllo 403 cross-company delle fatture.
- `StoreProductRequest`: nessuna regola su `company_id` (non è un campo del form).

## 5. Frontend (Inertia + Vue)

- Nuovo componente `resources/js/components/CompanySwitcher.vue`: dropdown (stesso pattern shadcn/`Select` o `DropdownMenu` già usato per il menu utente), popolato da `page.props.companies` (via `usePage()`), valore corrente da `page.props.currentCompany`. Alla selezione: `router.put('/current-company', { company_id })` con reload dei props condivisi (fatture/prodotti già visibili si aggiornano al filtro nuovo).
- `resources/js/components/AppHeader.vue`: inserisce `<CompanySwitcher />` nell'area destra (`ml-auto flex items-center space-x-2`), prima dell'avatar utente.
- `resources/js/pages/invoices/Create.vue` / `Edit.vue`: **rimosso** il `<Select>` per `company_id` e la prop `companies`/`defaultCompanyId`; `form` non include più `company_id`.
- `resources/js/pages/products/Create.vue` / `Edit.vue`: nessun campo company nel form (non esisteva già).
- Rotte generate via Wayfinder per `PUT /current-company`.

## 6. Testing (Pest, feature)

- `CurrentCompanyTest`: cambiare company via `PUT /current-company` aggiorna la sessione; fallback su `is_default` quando la sessione è vuota; 422 se `company_id` non esiste.
- `InvoiceTest`: `index` filtra per company corrente; `store` assegna automaticamente la company corrente ignorando eventuale `company_id` nel payload; numerazione riparte per ogni company (stesso anno, company diverse → entrambe `2026-0001`); 403 su edit/update/destroy di una fattura di un'altra company; duplicazione usa la company corrente.
- `ProductTest`: `index`/picker filtrano per company corrente; `store` assegna automaticamente la company corrente; 403 su edit/update/destroy di un prodotto di un'altra company.
- Factories: `ProductFactory` aggiorna per generare/associare una `Company`; test esistenti che creano prodotti/fatture aggiornati per impostare la company corrente in sessione dove serve.

## Note

Il DB di sviluppo viene svuotato/rigenerato con le nuove migration (`migrate:fresh`), nessuna migrazione dati da preservare oltre al backfill di `products.company_id` (comunque banale: tutta la company `is_default`). Nessuna gestione di ruoli/permessi differenziata per lo switcher: qualsiasi utente autenticato vede e può selezionare tutte le company.
