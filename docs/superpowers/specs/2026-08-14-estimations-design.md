# Preventivi (Estimations) — Design

## Purpose

New company-scoped entity, `Estimation` ("Preventivo"), for quoting products/services to a customer before invoicing: a markdown proposal body, line items copied from `products`, N attachments, PDF preview/export, a ZIP bundle (PDF + attachments) ready to send by email, and an explicit conversion into a real `Invoice` once accepted.

## Scope

New tables `estimations`, `estimation_rows`, and a generic polymorphic `attachments` table. New `Estimation`, `EstimationRow`, `Attachment` models and an `EstimationStatus` enum. Full CRUD (Index/Create/Edit) mirroring `invoices/*`, PDF preview/download, ZIP export (native `ZipArchive`, no new dependency), attachment upload/delete, and an explicit "convert to invoice" action. Markdown is rendered server-side via the already-installed `league/commonmark`, reused for both the live preview endpoint and the PDF template — no new npm dependency. Status changes are made only from the authenticated in-app Edit screen.

## Database schema

### `estimations`

| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| company_id | uuid, FK `companies` | not null |
| customer_id | uuid, FK `customers` | not null, immutable after creation (enforced in the update request, not the DB) |
| number | string | own sequence per company, format `YYYY-NNNN` (same shape as invoices, independent counter), unique per company |
| estimation_date | date | required |
| expiration_date | date | required, `after_or_equal:estimation_date` |
| language | string | required, one of `it`/`en`/`es` — mirrors `Invoice::language`, drives PDF locale |
| body | longtext, nullable | markdown proposal content |
| status | string (enum) | default `pending` |
| invoice_id | uuid, FK `invoices`, nullable | set once converted |
| created_at / updated_at | timestamps | |

### `estimation_rows`

| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| estimation_id | uuid FK, cascade delete | |
| description | string | copied from the chosen product at insert time |
| quantity | decimal | |
| price | decimal | |
| vat_rate | decimal | products carry no VAT of their own, same as `invoice_rows` |
| note | string, nullable | per-line note |
| created_at / updated_at | timestamps | |

No `product_id` column — rows are a value snapshot only, same convention as `invoice_rows`.

### `attachments` (generic, polymorphic — reusable beyond estimations)

| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| attachable_type | string | |
| attachable_id | uuid | indexed together with `attachable_type` |
| disk | string | `local` for now |
| path | string | storage path |
| original_name | string | original uploaded filename |
| mime_type | string | |
| size | unsigned integer | bytes |
| created_at / updated_at | timestamps | |

## Enums

### `App\Enums\EstimationStatus` (backed string, TitleCase keys, same style as `SubscriptionStatus`)
- `Pending = 'pending'`
- `Accepted = 'accepted'`
- `Rejected = 'rejected'`

No separate "expired" status is persisted. `Estimation` gets a computed, non-persisted accessor `isExpired`: `true` when `status === Pending` and `expiration_date < today()`.

## Models

### `App\Models\Estimation`
- `#[Fillable(['company_id', 'customer_id', 'estimation_date', 'expiration_date', 'language', 'body', 'status'])]`
- `casts()`: `estimation_date`/`expiration_date` → `date:Y-m-d`, `status` → `EstimationStatus::class`
- `booted()::creating` sets `number` via `nextNumber($estimation->company_id)` if not already set — same pattern as `Invoice`
- `nextNumber(string $companyId, ?string $year = null): string` — identical logic to `Invoice::nextNumber`, scoped to the `estimations` table (own counter, not shared with invoices)
- Relations: `company()` (`BelongsTo`), `customer()` (`BelongsTo`), `rows()` (`HasMany<EstimationRow>`), `invoice()` (`BelongsTo<Invoice>`, nullable), `attachments()` (`MorphMany<Attachment>`)
- Accessors `subtotal`/`vatTotal`/`total`: same computation as `Invoice`, derived from `rows`
- Accessor `isExpired` (`Attribute<bool, never>`): as defined above

### `App\Models\EstimationRow`
- `#[Fillable(['estimation_id', 'description', 'quantity', 'price', 'vat_rate', 'note'])]`
- `casts()`: `quantity`/`price`/`vat_rate` → `float`
- Relation `estimation()` (`BelongsTo`)
- Accessor `total`: same formula as `InvoiceRow::total`

### `App\Models\Attachment`
- `#[Fillable(['attachable_type', 'attachable_id', 'disk', 'path', 'original_name', 'mime_type', 'size'])]`
- `casts()`: `size` → `integer`
- Relation `attachable()` (`MorphTo`)
- No public `url()` accessor — files live on the private `local` disk; downloads are always streamed through an authorized controller action, never a direct public URL.

## Validation

### `StoreEstimationRequest`
```
customer_id       => required, uuid, exists:customers,id
estimation_date   => required, date
expiration_date   => required, date, after_or_equal:estimation_date
language          => required, string, in:it,en,es
body              => nullable, string
rows              => required, array, min:1
rows.*.description=> required, string, max:255
rows.*.quantity   => required, numeric, min:0.01
rows.*.price      => required, numeric, min:0
rows.*.vat_rate   => required, numeric, min:0, max:100
rows.*.note       => nullable, string, max:255
```
`status` is never accepted here — always created as `Pending`. `company_id` comes from `CurrentCompany::resolve()`, not the request.

### `UpdateEstimationRequest`
Same as above, minus `customer_id` (immutable — not validated, not read from the request), plus:
```
status => required, Rule::enum(EstimationStatus::class)
```
Row diffing (create/update/delete by row id) follows `InvoiceController::update()`'s existing manual-diff pattern.

If `$estimation->invoice_id` is already set, `EstimationController::update()` rejects the request (flash error, redirect back) — once converted, the estimation is read-only to preserve what was actually invoiced.

### `StoreEstimationAttachmentRequest`
```
file => required, file, mimes:pdf,jpg,jpeg,png,doc,docx,rtf,md, max:10240
```
`mimes:` resolves by extension against Laravel's MIME map; `.md` isn't guaranteed to be in that map, so this needs a Pest test up front and, if it fails, an extension-based fallback check (`Rule::in` on `$file->getClientOriginalExtension()`) rather than relying solely on `mimes:`.

## Controllers

### `EstimationController` (mirrors `InvoiceController`)
- `index()` — search by number/customer name, paginate 15, scoped to `CurrentCompany`
- `create()` — customers list, `nextNumber`, `?duplicate=` support (same as `InvoiceController::create`)
- `store(StoreEstimationRequest)` — `DB::transaction`: create `Estimation` (`company_id` from `CurrentCompany`, `status` defaults `Pending`) + `rows()->createMany()`
- `edit(Estimation $estimation)` — `authorizeCurrentCompany`; loads `rows`, `attachments`, `invoice`
- `update(UpdateEstimationRequest, Estimation $estimation)` — `authorizeCurrentCompany`; blocked if `invoice_id` is set (see above); row diff as in `InvoiceController::update`
- `destroy(Estimation $estimation)` — `authorizeCurrentCompany`; blocked (flash error, no delete) if `status === Accepted` or `invoice_id` is set; otherwise deletes the estimation (rows cascade in DB) and removes attachment files from disk before deleting their records
- `preview(Estimation $estimation): View` — sets locale from `$estimation->language`, renders markdown `body` to HTML, returns `estimations.template`
- `pdf(Estimation $estimation): HttpResponse` — same view via `Pdf::loadView(...)->download(...)`
- `zip(Estimation $estimation): HttpResponse` — see ZIP section below
- `convertToInvoice(Estimation $estimation): RedirectResponse` — guarded to `status === Accepted && invoice_id === null` (else flash error, redirect back); `DB::transaction`: create `Invoice` (`company_id`, `customer_id`, `language` copied from the estimation, `invoice_date = today()`, `paid = false`) + `InvoiceRow` per `EstimationRow` (`description`, `quantity`, `price`, `vat_rate`); set `$estimation->invoice_id`; flash success; redirect to `invoices.edit` for the new invoice

### `EstimationAttachmentController`
- `store(StoreEstimationAttachmentRequest, Estimation $estimation)` — `authorizeCurrentCompany` via the estimation; stores the file on `Storage::disk('local')` under `estimations/{estimation->id}/attachments/{uuid}.{extension}`; creates the `Attachment` record; redirects back with a flash message
- `destroy(Estimation $estimation, Attachment $attachment)` — verifies `$attachment->attachable_id === $estimation->id` (404 otherwise) and company ownership via the estimation; deletes the file from disk then the record; redirects back

### Markdown rendering
A small `App\Support\MarkdownRenderer` wrapping a single configured `League\CommonMark\CommonMarkConverter` instance (default safe settings — no raw HTML passthrough), used by:
- `POST estimations/{estimation}/markdown-preview` — accepts a `body` string in the request (not the stored value, so it reflects unsaved edits), returns `{ html }`, used by the Edit/Create form's live preview tab
- `preview()`/`pdf()` — renders the persisted `body`

## Routes (`routes/estimations.php`, required from `routes/web.php`)

```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('estimations/{estimation}/preview', [EstimationController::class, 'preview'])->name('estimations.preview');
    Route::get('estimations/{estimation}/pdf', [EstimationController::class, 'pdf'])->name('estimations.pdf');
    Route::get('estimations/{estimation}/zip', [EstimationController::class, 'zip'])->name('estimations.zip');
    Route::post('estimations/{estimation}/convert-to-invoice', [EstimationController::class, 'convertToInvoice'])->name('estimations.convert-to-invoice');
    Route::post('estimations/{estimation}/markdown-preview', [EstimationController::class, 'markdownPreview'])->name('estimations.markdown-preview');
    Route::post('estimations/{estimation}/attachments', [EstimationAttachmentController::class, 'store'])->name('estimations.attachments.store');
    Route::delete('estimations/{estimation}/attachments/{attachment}', [EstimationAttachmentController::class, 'destroy'])->name('estimations.attachments.destroy');
    Route::resource('estimations', EstimationController::class)->except('show');
});
```

## ZIP export

`EstimationController::zip()`:
1. Render the PDF the same way `pdf()` does, via `Pdf::loadView(...)->output()` (in-memory bytes).
2. Build a temp file with `ZipArchive`: add the PDF bytes as `{number}.pdf`, then loop `$estimation->attachments` adding each via `Storage::disk($a->disk)->path($a->path)` under its `original_name`.
3. Return `response()->download($tmpPath, "{$estimation->number}.zip")->deleteFileAfterSend()`.

No email sending in this iteration — the ZIP is a manual-download deliverable.

## PDF/preview template

New `resources/views/estimations/template.blade.php`, structurally aligned with `resources/views/invoices/template.blade.php`: header (logo, company, customer, status badge, number/dates), rows table, totals, a rendered-markdown "proposal" section, footer showing the expiration date.

## Frontend

- `resources/js/pages/estimations/{Index,Create,Edit}.vue`, mirroring `invoices/*`:
  - `Edit.vue` adds: a status `<select>` (Pending/Accepted/Rejected), an "Scaduto" badge when `isExpired`, an attachments panel (upload input + list with delete/download), and a "Converti in Fattura" action — enabled only when `status === Accepted && invoice_id === null`; once `invoice_id` is set, it becomes a link to the generated invoice instead. Preview/PDF/ZIP icon-buttons follow the same placement `invoices/Edit.vue` already uses for `Eye`/`FileText` (preview/pdf).
  - Markdown `body` field: textarea with a Modifica/Anteprima tab toggle; the Anteprima tab calls the `markdown-preview` endpoint (debounced) and renders the returned HTML.
- `AppSidebar.vue`: new nav item "Preventivi" (`estimations.index`, a `FileText`-family lucide icon not already used for another entry).
- Translation keys added to `resources/js/lang/{en,it,es}.ts` under `nav.estimations` and a new `estimations.*` block mirroring the existing `invoices.*` structure (status labels, attachment labels, convert action, expired badge).

## Testing (Pest)

- `Estimation`/`EstimationRow` unit tests: `nextNumber` sequencing per company, `subtotal`/`vatTotal`/`total` computation, `isExpired` accessor (pending+past, pending+future, accepted+past → not expired since status isn't pending).
- `EstimationTest` (feature): CRUD scoped to current company (403 across companies); `customer_id` ignored/rejected on update; delete blocked when `Accepted` or `invoice_id` set; update blocked once `invoice_id` is set.
- `EstimationConversionTest`: `convertToInvoice` creates the `Invoice`+rows correctly, sets `invoice_id`, is blocked when status isn't `Accepted` or already converted.
- `EstimationAttachmentTest`: upload accepts each allowed extension (including `.md`) and rejects disallowed/oversized files; delete removes both the DB record and the disk file; both actions 403 across companies.
- `EstimationPdfTest`: `preview`/`pdf`/`zip` return successful responses for an authorized estimation, 403 otherwise; `zip` response contains the expected number of entries (PDF + attachments).
- `MarkdownPreviewTest`: endpoint renders known markdown input to expected HTML fragment.

## Out of scope

- Public/unauthenticated customer-facing acceptance page — status changes only from the authenticated in-app Edit screen.
- Direct email sending from the app — the ZIP is a manual download.
- A persisted "expired" status — it is a computed, non-persisted indicator only.
- Editing an estimation after it has been converted to an invoice.
- Any change to `products`, `invoices`, or `invoice_rows` beyond the new nullable-from-estimation creation path in `convertToInvoice()`.
