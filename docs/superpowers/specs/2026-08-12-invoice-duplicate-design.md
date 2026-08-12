# Invoice Duplicate ("Fattura per copia") — Design

## Purpose

Let the user create a new invoice starting from an existing one, without re-typing customer, note, language, and rows. Clicking "Duplica" on the invoice list opens the existing "New invoice" form pre-filled with the source invoice's data — number and date are always freshly computed, never copied, and the invoice is only persisted when the user confirms with Save.

## Scope

Builds on the existing invoices CRUD (`docs/superpowers/specs/2026-08-12-invoices-design.md`). No new routes, no new controller actions, no database changes — only an optional query parameter on the existing `create` route, a new Inertia prop, and a new link on the list page.

## Backend — `InvoiceController::create()`

Accepts an optional `duplicate` query parameter holding the source invoice's id. When present and resolvable, the action loads that invoice with its `rows` and passes a `duplicate` prop to the view containing:

- `customer_id`
- `note`
- `language`
- `rows` — array of `{ description, price, vat_rate }` (no row `id`, since these become brand-new rows)

`paid` is never included — the duplicated form always starts unpaid, matching the default `useForm` value already in `Create.vue`. `number` (`Invoice::nextNumber()`) and the date default (today, computed client-side as before) are unaffected by duplication — they are never sourced from the original invoice.

Lookup is a plain `Invoice::query()->with('rows')->find($id)`, not route-model binding: an invalid, missing, or malformed id is not an error condition — the parameter is only a prefill hint, so the action silently falls back to `duplicate: null` and renders a normal blank create form.

```php
public function create(Request $request): Response
{
    $duplicateId = $request->string('duplicate')->trim()->toString();
    $source = $duplicateId !== ''
        ? Invoice::query()->with('rows')->find($duplicateId)
        : null;

    return Inertia::render('invoices/Create', [
        'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
        'nextNumber' => Invoice::nextNumber(),
        'duplicate' => $source ? [
            'customer_id' => $source->customer_id,
            'note' => $source->note,
            'language' => $source->language,
            'rows' => $source->rows->map(fn ($row) => [
                'description' => $row->description,
                'price' => $row->price,
                'vat_rate' => $row->vat_rate,
            ])->all(),
        ] : null,
    ]);
}
```

## Frontend — `Create.vue`

New optional prop:

```ts
duplicate: {
    customer_id: string;
    note: string;
    language: string;
    rows: InvoiceRowForm[];
} | null;
```

`useForm`'s initial values use `props.duplicate` fields when present, falling back to the current defaults otherwise:

```ts
const form = useForm({
    number: props.nextNumber,
    invoice_date: new Date().toISOString().slice(0, 10),
    paid: false,
    customer_id: props.duplicate?.customer_id ?? '',
    note: props.duplicate?.note ?? '',
    language: props.duplicate?.language ?? 'es',
    rows: props.duplicate?.rows ?? [{ description: '', price: 0, vat_rate: 0 }],
});
```

No banner, badge, or other visual indication that the form was pre-filled from a duplicate — the pre-filled fields speak for themselves, and the user reviews/edits before saving like any other new invoice.

## Frontend — `Index.vue`

In the actions column, alongside Preview/PDF/Edit, add a "Duplica" `Link` navigating to `create({ query: { duplicate: invoice.id } })` (uses the existing generated `create` Wayfinder helper, which already accepts `RouteQueryOptions`).

## Testing

Feature test appended to `tests/Feature/InvoiceTest.php`:

- `GET /invoices/create?duplicate={id}` for an existing invoice returns the Inertia `duplicate` prop populated with the source invoice's `customer_id`, `note`, `language`, and rows (`description`/`price`/`vat_rate`), with no `paid` key and no row `id`s.
- `GET /invoices/create?duplicate={invalid-or-missing-id}` returns `duplicate: null` (falls back to a normal blank create page) instead of a 404.
- `GET /invoices/create` (no `duplicate` param) still returns `duplicate: null`, unchanged from current behavior.

## Out of scope

- Any immediate/one-click duplication that persists a new invoice without user review (rejected in favor of a pre-filled, still-editable form).
- Copying `paid` status, `number`, or `invoice_date` from the source invoice.
- A "duplicated from #X" reference stored on the new invoice.
