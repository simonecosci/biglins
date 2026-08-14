# Scadenziario (Servizi a scadenza) — Design

## Purpose

Track recurring/expiring services (domains, hosting, maintenance contracts) directly on invoice rows, and surface them on the Dashboard as a renewal worklist grouped by the invoice they came from, with one-click renewal that generates a new invoice.

## Scope

Extend `invoice_rows` with an optional `expiration_date` and a `subscription_status`. Add model logic to classify rows by urgency and scope to active subscriptions. Add an optional "Data Scadenza" field to the invoice row form. Build a Dashboard widget that groups active subscription rows by source invoice, shows KPIs and per-group renew/cancel actions. No new tables; no changes to invoice numbering, VAT, or unrelated invoice fields.

## Database schema

New migration `add_expiration_date_and_subscription_status_to_invoice_rows_table` (the `invoice_rows` table migration already ran, so it is not edited in place):

| Column | Type | Notes |
|---|---|---|
| expiration_date | date, nullable, default null, after `vat_rate` | NULL = not a subscription row |
| subscription_status | string, default `'active'`, after `expiration_date` | `active` \| `cancelled` |

## Enums

### `App\Enums\SubscriptionStatus` (backed string, same style as `ProductType`)
- `Active = 'active'`
- `Cancelled = 'cancelled'`

### `App\Enums\ExpirationUrgency` (backed string, computed only — never persisted)
- `Expired = 'expired'`
- `ExpiringSoon = 'expiring_soon'`
- `Upcoming = 'upcoming'`

## Models

### `App\Models\InvoiceRow`
- `#[Fillable([..., 'expiration_date', 'subscription_status'])]`
- `casts()` adds `'expiration_date' => 'date', 'subscription_status' => SubscriptionStatus::class`
- `scopeSubscriptions(Builder $query): Builder` — `whereNotNull('expiration_date')->where('subscription_status', SubscriptionStatus::Active)`
- `expirationUrgency` accessor (`Attribute<ExpirationUrgency|null, never>`, not appended/persisted):
  - `null` if `expiration_date` is null
  - `Expired` if `expiration_date < today()`
  - `ExpiringSoon` if `expiration_date` between `today()` and `today()->addDays(30)` inclusive
  - `Upcoming` otherwise

### `App\Models\Invoice`
No schema/accessor changes. Used as the grouping key for the widget (`invoice_id`) and as the template for the renewal invoice (`customer_id`, `company_id`, `language`).

## Validation

`StoreInvoiceRequest` and `UpdateInvoiceRequest` add:

```
rows.*.expiration_date => nullable|date
```

`subscription_status` is never accepted from these requests — it always defaults to `active` on creation and is only ever changed by the Subscription controller actions below.

## Controller: invoice rows

`InvoiceController::update()`'s manual row diff (create/update/delete by row id) includes `expiration_date` among the updatable fields, alongside `description`, `quantity`, `price`, `vat_rate`.

## Controller: subscriptions (new)

`App\Http\Controllers\SubscriptionController`. `InvoiceRow` has no `company_id` of its own, so every method scopes through the parent invoice: `whereHas('invoice', fn ($q) => $q->where('company_id', CurrentCompany::resolve()?->id))`, mirroring the existing `ScopesToCurrentCompany` pattern used elsewhere. Route-model-bound records outside the current company `abort(403)`.

- `renew(Invoice $invoice): RedirectResponse`
  1. Authorize: `$invoice->company_id === CurrentCompany::resolve()?->id`, else 403.
  2. `DB::transaction`: load `$invoice->rows()->subscriptions()->get()`; abort 404/422 if empty.
  3. Create a new `Invoice` (`company_id`, `customer_id`, `language` copied from `$invoice`; `invoice_date = today()`; `paid = false`; `note = null`).
  4. For each source row, create a new row on the new invoice: same `description`/`quantity`/`price`/`vat_rate`, `expiration_date = $row->expiration_date->addYear()`, `subscription_status = active`.
  5. Set `subscription_status = cancelled` on the source rows (only the ones just renewed).
  6. Redirect to `invoices.edit` for the new invoice, with a flash success message.

- `cancelRow(InvoiceRow $row): RedirectResponse` — authorize via `$row->invoice->company_id`; set `subscription_status = cancelled` on that row; redirect back.

- `cancelGroup(Invoice $invoice): RedirectResponse` — authorize via `$invoice->company_id`; bulk-update `subscription_status = cancelled` on `$invoice->rows()->subscriptions()`; redirect back.

## Routes

```
POST /subscriptions/{invoice}/renew   -> subscriptions.renew
POST /subscriptions/{invoice}/cancel  -> subscriptions.cancel
POST /invoice-rows/{invoiceRow}/cancel -> invoice-rows.cancel
```

All behind the existing `auth` middleware group used by `invoices.*` routes.

## Dashboard

`DashboardController::__invoke()` (currently an empty placeholder render) gathers, for the current company:

- All rows via `InvoiceRow::subscriptions()->whereHas('invoice', ...)->with('invoice.customer')->get()`.
- KPIs: count where `expirationUrgency === Expired`, count where `expirationUrgency === ExpiringSoon`.
- Groups: rows grouped by `invoice_id`, each group carrying `customer`, `rows` (description, price, quantity, expiration_date, urgency), group total, and an overall badge (`red` if any row expired, `orange` if any expiring soon, `green` otherwise).

Passed to `Inertia::render('Dashboard', [...])`.

### Frontend

New component `resources/js/components/dashboard/SubscriptionsWidget.vue` (uses existing shadcn `Card`, `Badge`, `Button`), mounted into `Dashboard.vue` in place of one of the current placeholder blocks:

- Two KPI counters (expired = red, expiring soon = orange).
- One card per invoice group: customer name, list of rows (description, price, expiration date), group total, overall status badge, "Rinnova Gruppo" and "Annulla Gruppo" buttons, plus a per-row "Annulla" action.
- Actions POST via Inertia `router.post` to the routes above; renew navigates to the resulting invoice edit page, cancels stay on the Dashboard and refresh the widget's props.

### Invoice row form (Create.vue / Edit.vue)

- Row grid extends from 6 to 7 columns: new `expiration_date` column (native `type="date"` input, optional) placed next to VAT, before the delete button. `grid-cols-[...]` template widens accordingly.
- `InvoiceRowForm` TS type gains `expiration_date: string | null`; default new row includes `expiration_date: null`.
- `InputError` for `rows.${i}.expiration_date` added, matching the existing per-field error pattern.
- New translation key `rowExpirationDate` (and any label/placeholder needed) added to `resources/js/lang/en.ts`, `it.ts`, `es.ts` under `invoices.create.*`.
- `subscription_status` is not exposed in this form — it stays `active` until changed via the Dashboard widget.

## Factory

`InvoiceRowFactory` gains a `subscription()` state: sets `expiration_date` to a random date within roughly -60..+120 days of today (covering expired/expiring-soon/upcoming) and leaves `subscription_status` at its default (`active`).

## Testing (Pest)

- `InvoiceRow` unit tests: `scopeSubscriptions` returns only rows with non-null `expiration_date` and `active` status; `expirationUrgency` accessor for all four cases (null, expired, expiring soon, upcoming), including the boundary at exactly 30 days.
- `SubscriptionTest` (new feature test):
  - `renew` creates a new invoice with copied rows, `expiration_date + 1 year`, marks source rows `cancelled`, redirects to the new invoice's edit page.
  - `renew` only touches rows that were active subscriptions on that invoice (rows without `expiration_date`, or already `cancelled`, are left alone and not duplicated).
  - `renew`/`cancelRow`/`cancelGroup` 403 when the invoice/row belongs to another company.
  - `cancelRow` and `cancelGroup` set `subscription_status = cancelled` correctly.
- `DashboardTest`: widget props (KPI counts, groups) are present, correctly grouped by invoice, and scoped to the current company only.
- `InvoiceTest`: creating/updating an invoice with `rows.*.expiration_date` persists it; omitting it still works (nullable).

## Out of scope

- Changes to the PDF/preview invoice template (`resources/views/invoices/template.blade.php`) — expiration dates are a back-office concern, not shown on the printed invoice.
- Email/notification reminders for upcoming expirations.
- Editing `subscription_status` from the invoice row form.
- A denormalized `company_id` column on `invoice_rows` (scoping stays via `invoice.company_id`, consistent with the rest of the codebase).
