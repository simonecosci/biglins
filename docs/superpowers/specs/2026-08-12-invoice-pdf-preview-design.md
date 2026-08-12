# Invoice HTML Preview & Multilingual PDF — Design

## Purpose

Let the user preview an invoice as HTML and download it as a PDF, in one of three languages (Italian, English, Spanish) chosen per-invoice. The invoice document template is a single Blade file the user edits directly to insert their own freelancer/company details and logo.

## Scope

Adds a `language` field to invoices, a Blade invoice template shared by both the HTML preview and the PDF export, per-language translation strings, a `config/company.php` file for the issuer's own data, two new controller actions + routes, PDF generation via `barryvdh/laravel-dompdf`, and the corresponding frontend links/fields on the existing Invoices pages. Builds directly on the existing invoices CRUD (`docs/superpowers/specs/2026-08-12-invoices-design.md`).

## Dependency

Add `barryvdh/laravel-dompdf` via Composer (`composer require barryvdh/laravel-dompdf`). Chosen over `spatie/laravel-pdf` because it renders Blade → PDF directly with no Node/Chromium dependency, keeping the deployment footprint unchanged.

## Database schema

### `invoices` (alter)

| Column | Type | Notes |
|---|---|---|
| language | string(2), default `es` | one of `it`, `en`, `es` |

Migration: `database/migrations/2026_08_12_100000_add_language_to_invoices_table.php`, adding the column after `note`.

## Company/issuer data — `config/company.php`

A new config file, not database-backed, holding the freelancer's own invoicing details. The user edits this file directly (no UI). Keys, all strings, all with placeholder values to be filled in by the user:

```php
return [
    'name' => 'Your Name / Business Name',
    'tax_id' => 'IT00000000000',
    'address' => 'Via Esempio 1',
    'zip' => '00100',
    'city' => 'Roma',
    'country' => 'Italia',
    'email' => 'you@example.com',
    'phone' => '+39 000 0000000',
    'iban' => 'IT00X0000000000000000000000',
    'logo' => 'images/logo.png', // relative to public/, resolved with asset()
];
```

The logo file itself is a static asset at `public/images/logo.png` (not committed with real content — the user drops their own file in place). The template guards with `@if(file_exists(public_path(config('company.logo'))))` so a missing logo doesn't break rendering.

## Translations

`resources/lang/{it,en,es}/invoice.php`, each returning the same key set, e.g.:

```php
return [
    'title' => 'Fattura', // Invoice / Factura
    'number' => 'Numero',
    'date' => 'Data',
    'customer' => 'Cliente',
    'description' => 'Descrizione',
    'price' => 'Prezzo',
    'vat' => 'IVA',
    'subtotal' => 'Subtotale',
    'total' => 'Totale',
    'note' => 'Note',
    'paid' => 'Pagata',
    'unpaid' => 'Non pagata',
    'tax_id' => 'P.IVA',
    'iban' => 'IBAN',
];
```

## Invoice template

`resources/views/invoices/template.blade.php` — a single self-contained Blade file (inline `<style>`, no Tailwind/build step, since it must render identically through dompdf and a browser). Receives an `$invoice` (with `customer` and `rows` eager-loaded). Layout, top to bottom:

1. Header: `config('company.logo')` (if present) + company block (name, address, tax_id, email, phone) on one side, invoice metadata (number, date, paid/unpaid badge) on the other.
2. "Bill to" block: customer name, address, zip/city, country, tax id (`nif`), email — from the `Customer` model.
3. Rows table: description / price / VAT % / row total, one line per `InvoiceRow`.
4. Totals block: subtotal, VAT total, total (right-aligned, using the existing `Invoice` accessors).
5. **Notes section, placed last, after the totals** — only rendered `@if($invoice->note)`.

All labels come from `__('invoice.*')`. The controller sets `App::setLocale($invoice->language)` before building the view/PDF response so `__()` resolves to the right file. It does **not** restore the previous locale afterward: `view()` renders lazily, so restoring the locale right after the call (before Laravel's response pipeline actually renders the template) would flip the labels back before they're produced. Since both `preview` and `pdf` are terminal, single-response actions and each HTTP request gets a fresh application instance, there's no other code in the same request that could observe the changed locale — leaving it set for the rest of the request is safe and simpler than working around the lazy-render ordering.

## Controller & routes

New actions on the existing `App\Http\Controllers\InvoiceController`:

- `preview(Invoice $invoice): View` — sets the locale, returns `view('invoices.template', ['invoice' => $invoice->load(['customer', 'rows'])])` directly (bypasses Inertia; plain HTML response).
- `pdf(Invoice $invoice): Response` — same view data, rendered through `Pdf::loadView('invoices.template', [...])->download("{$invoice->number}.pdf")` (via the `Barryvdh\DomPDF\Facade\Pdf` facade).

Routes added to `routes/invoices.php`, inside the existing `auth`+`verified` group, alongside the resource route:

```php
Route::get('invoices/{invoice}/preview', [InvoiceController::class, 'preview'])->name('invoices.preview');
Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
```

## Validation

`language` added to `StoreInvoiceRequest`/`UpdateInvoiceRequest`: `['required', 'string', Rule::in(['it', 'en', 'es'])]`. `InvoiceController@create` no longer needs to pass a default — the frontend form defaults the select to `es`.

## Models

`Invoice`: add `language` to `#[Fillable(...)]`. No cast needed (plain string). No accessor changes.

## Frontend (Vue/Inertia)

- **Create.vue / Edit.vue**: new "Language" `Select` field (Italiano `it` / English `en` / Español `es`), positioned near `customer_id`. `Create.vue`'s form defaults `language: 'es'`. `Edit.vue` prefills from `invoice.language`.
- **Index.vue**: each row gets two plain `<a>` links (not Inertia `<Link>`, since these are non-Inertia HTML/binary responses) — "Preview" (`target="_blank"`, href from `invoices.preview`) and "PDF" (href from `invoices.pdf`, native browser download via `Content-Disposition: attachment`).
- **Edit.vue**: same two links added near the page heading.
- Wayfinder regenerated (`php artisan wayfinder:generate`) so `@/actions/App/Http/Controllers/InvoiceController` exposes `.preview(id)` and `.pdf(id)` helpers alongside the existing CRUD ones.

## Testing

- Feature tests appended to `tests/Feature/InvoiceTest.php`:
  - `store`/`update` validation: `language` required and restricted to `it|en|es`.
  - `preview` route: 200, HTML content type, response body contains the invoice number, the customer's name, and the note text when a note is set.
  - `preview` route with `language = 'en'` vs `'it'` renders the corresponding translated label (e.g. asserts `"Invoice"` appears for `en`, `"Fattura"` for `it`).
  - `pdf` route: 200, `Content-Type: application/pdf`, `Content-Disposition` contains the invoice number.
  - Guests are redirected to login for both new routes (same middleware group as the rest of the resource).

## Out of scope

- Uploading the logo through the UI (it's a static file the user places manually).
- A settings page for company data (config file only, edited directly).
- Emailing the PDF to the customer.
- Per-customer default language persisted on the `Customer` model (language is set per-invoice only, defaulting to `es`).
- Currency/number localization beyond the label translations (amounts are shown as plain decimals, no locale-specific formatting).
