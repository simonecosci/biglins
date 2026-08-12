# Invoice Row Quantity — Design

## Purpose

Add a `quantity` field to invoice rows so a row's total is `price * quantity` (plus VAT), instead of always being a single unit price. This reverses the explicit "no quantity" decision in the original [invoices design](2026-08-12-invoices-design.md), which is being revisited at the user's request.

## Scope

Add `quantity` end-to-end: migration, model, validation, controller duplication mapping, factory, frontend forms (Create/Edit), PDF/preview template, and tests. No changes to numbering, customer/company logic, or unrelated invoice fields.

## Database schema

New migration (the `invoice_rows` table migration already ran elsewhere, so it is not edited in place):

| Column | Type | Notes |
|---|---|---|
| quantity | decimal(10,2), default `1.00` | multiplied against `price`; decimal to allow fractional quantities (e.g. hours) |

## Models

### `App\Models\InvoiceRow`

- `#[Fillable(['invoice_id', 'description', 'quantity', 'price', 'vat_rate'])]`
- Cast `quantity` → `float`.
- `total()` accessor becomes:
  `price * quantity + price * quantity * vat_rate / 100`

### `App\Models\Invoice`

- `subtotal()`: `sum(price * quantity)` per row.
- `vatTotal()`: `sum(price * quantity * vat_rate / 100)` per row.
- `total()`: unchanged (`subtotal + vatTotal`).

## Validation

`StoreInvoiceRequest` and `UpdateInvoiceRequest` add:

```
rows.*.quantity => required|numeric|min:0.01
```

`min:0.01` rejects zero/negative quantities — a zero-quantity row is meaningless and likely a data-entry mistake.

## Controller

`InvoiceController::create()`'s duplicate-mapping array (used to prefill a new invoice from an existing one) adds `quantity` alongside `description`, `price`, `vat_rate`. `store()`/`update()` already pass `rows` through generically (mass-assignment via `Fillable`), so no other controller change is needed.

## Factory

`InvoiceRowFactory` adds `'quantity' => fake()->numberBetween(1, 5)`.

## Frontend (Vue/Inertia)

`Create.vue` and `Edit.vue`:

- New "Quantity" column between Description and Price in the rows grid (header + inputs), using a numeric input (`step="0.01"`, `min="0.01"`).
- Row shape gains `quantity: number`; default new row is `{ description: '', quantity: 1, price: 0, vat_rate: 0 }` (`quantity: 1` in `Edit.vue`'s `addRow()` too).
- The client-side running `total` computed multiplies `row.price * row.quantity` (and VAT on that product) instead of `row.price` alone.
- Grid column template (`grid-cols-[...]`) widens to fit the new column.

## PDF / preview template

`resources/views/invoices/template.blade.php`:

- New "Quantity" column in the rows table, between Description and Price.
- Row total column already uses `$row->total`, which picks up the new calculation automatically.
- New `quantity` translation key added to `resources/lang/{en,it,es}/invoice.php`.

## Testing

- `InvoiceRow` unit test: `total` accessor multiplies price by quantity before applying VAT (e.g. price=100, quantity=2, vat=22 → total=244).
- `Invoice` unit test: `subtotal`/`vat_total`/`total` accessors account for row quantities.
- Feature tests: creating/updating an invoice with `rows.*.quantity` persists and validates (rejects `quantity: 0`).
- Existing tests that omit `quantity` from request payloads are updated to include it (now required), or asserted to fail validation if that's the point of the test.
- `InvoiceTemplateTest.php`: preview HTML contains the quantity value for a row.

## Out of scope

- Editing the already-applied `invoice_rows` migration.
- Changing how VAT or numbering work.
- A stored/denormalized row-total column (still a computed accessor).
