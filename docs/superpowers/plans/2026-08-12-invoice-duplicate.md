# Invoice Duplicate ("Fattura per copia") Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Duplica" action to the invoices list that opens the New Invoice form pre-filled with an existing invoice's customer, note, language, and rows — leaving number, date, and paid status untouched — so the user can review and save it as a new invoice.

**Architecture:** No new routes or database changes. `InvoiceController::create()` gains an optional `duplicate` query parameter; when it resolves to an existing invoice, its data is passed as a new `duplicate` Inertia prop. `Create.vue` seeds `useForm()` from that prop when present. `Index.vue` adds a link that navigates to `create()` with `?duplicate={id}` via the existing Wayfinder-generated route helper.

**Tech Stack:** Laravel 13, Inertia v3 (Vue 3), Pest 5, Wayfinder-generated TS route helpers.

## Global Constraints

- `paid` is never copied from the source invoice — the duplicated form always starts with `paid: false`.
- `number` and `invoice_date` are never sourced from the source invoice — they always use the existing fresh defaults (`Invoice::nextNumber()` server-side, today's date client-side).
- An invalid, missing, or non-existent `duplicate` id must not error (no 404) — it silently falls back to a normal blank create form (`duplicate: null`).
- No new database migrations, routes, or controller actions — only `InvoiceController::create()` is modified.

---

### Task 1: Backend — `duplicate` query param on `InvoiceController::create()`

**Files:**
- Modify: `app/Http/Controllers/InvoiceController.php:41-47` (the `create` method)
- Test: `tests/Feature/InvoiceTest.php` (append)

**Interfaces:**
- Consumes: `App\Models\Invoice` (existing `customer_id`, `note`, `language` properties and `rows()` relation with `description`/`price`/`vat_rate` on `App\Models\InvoiceRow`), `App\Models\Customer`.
- Produces: `InvoiceController::create(Request $request): Response` now renders `invoices/Create` with an additional `duplicate` prop of shape `array{customer_id: string, note: ?string, language: string, rows: list<array{description: string, price: float, vat_rate: float}>}|null`, consumed by Task 2's `Create.vue`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/InvoiceTest.php`:

```php
test('invoice create page prefills from a duplicate query param', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $source = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'note' => 'Source note',
        'language' => 'en',
        'paid' => true,
    ]);
    InvoiceRow::factory()->create([
        'invoice_id' => $source->id,
        'description' => 'Consulting',
        'price' => 100,
        'vat_rate' => 22,
    ]);

    $response = $this->actingAs($user)->get(route('invoices.create', ['duplicate' => $source->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate.customer_id', $customer->id)
        ->where('duplicate.note', 'Source note')
        ->where('duplicate.language', 'en')
        ->where('duplicate.rows.0.description', 'Consulting')
        ->where('duplicate.rows.0.price', 100.0)
        ->where('duplicate.rows.0.vat_rate', 22.0)
        ->missing('duplicate.paid')
        ->missing('duplicate.rows.0.id')
    );
});

test('invoice create page ignores an invalid duplicate id', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('invoices.create', ['duplicate' => 'not-a-real-id']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate', null)
    );
});

test('invoice create page has no duplicate data without the query param', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('invoices.create'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate', null)
    );
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="invoice create page"`
Expected: FAIL — the `duplicate` prop doesn't exist yet, so `where('duplicate.customer_id', ...)` and `where('duplicate', null)` assertions fail.

- [ ] **Step 3: Implement the `duplicate` query param**

In `app/Http/Controllers/InvoiceController.php`, replace the `create` method:

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
            'rows' => $source->rows->map(fn (\App\Models\InvoiceRow $row): array => [
                'description' => $row->description,
                'price' => $row->price,
                'vat_rate' => $row->vat_rate,
            ])->all(),
        ] : null,
    ]);
}
```

- [ ] **Step 4: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter="invoice create page"`
Expected: PASS (all three new tests, plus the existing `invoice create page can be rendered` test still passes).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/InvoiceController.php tests/Feature/InvoiceTest.php
git commit -m "feat: prefill invoice create page from a duplicate query param"
```

---

### Task 2: Frontend — pre-fill `Create.vue` and add the "Duplica" link on `Index.vue`

**Files:**
- Modify: `resources/js/pages/invoices/Create.vue:33-53` (props type and `useForm` initialization)
- Modify: `resources/js/pages/invoices/Index.vue:111-132` (actions column)

**Interfaces:**
- Consumes: the `duplicate` prop shape produced by Task 1 (`{ customer_id: string; note: string | null; language: string; rows: { description: string; price: number; vat_rate: number }[] } | null`); the existing generated `create` route helper from `@/routes/invoices` (`create(options?: { query?: Record<string, unknown> }): RouteDefinition<'get'>`, already supports `{ query: {...} }` — see `resources/js/routes/invoices/index.ts:289-292`).
- Produces: nothing consumed by later tasks — this is the last task.

- [ ] **Step 1: Add the `duplicate` prop and seed the form in `Create.vue`**

In `resources/js/pages/invoices/Create.vue`, change the `defineProps` block (currently at lines 33-36):

```ts
const props = defineProps<{
    customers: Customer[];
    nextNumber: string;
    duplicate: {
        customer_id: string;
        note: string | null;
        language: string;
        rows: InvoiceRowForm[];
    } | null;
}>();
```

Then change the `useForm` initialization (currently at lines 46-54):

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

- [ ] **Step 2: Add the "Duplica" link in `Index.vue`**

In `resources/js/pages/invoices/Index.vue`, add `create` to the existing import from `@/routes/invoices` (currently line 10):

```ts
import { create, edit, index } from '@/routes/invoices';
```

(already imports `create` — no change needed there; confirm it's present before editing the template.)

In the actions cell (currently lines 111-132), add a "Duplica" link after the "Edit" link:

```vue
                        <td class="px-4 py-2 text-right space-x-3">
                            <a
                                :href="InvoiceController.preview(invoice.id).url"
                                target="_blank"
                                rel="noopener"
                                class="text-primary underline-offset-4 hover:underline"
                            >
                                Preview
                            </a>
                            <a
                                :href="InvoiceController.pdf(invoice.id).url"
                                class="text-primary underline-offset-4 hover:underline"
                            >
                                PDF
                            </a>
                            <Link
                                :href="edit(invoice.id)"
                                class="text-primary underline-offset-4 hover:underline"
                            >
                                Edit
                            </Link>
                            <Link
                                :href="create({ query: { duplicate: invoice.id } })"
                                class="text-primary underline-offset-4 hover:underline"
                            >
                                Duplica
                            </Link>
                        </td>
```

- [ ] **Step 3: Build frontend assets**

Run: `npm run build`
Expected: build succeeds with no TypeScript errors (the `duplicate` prop type must match how it's used — `props.duplicate?.customer_id` etc. all resolve against the new type).

- [ ] **Step 4: Manually verify the golden path in the browser**

Start the dev server if not already running (`composer run dev` or ask the user to run it), then:
1. Go to the invoices list, confirm a "Duplica" link appears in each row's actions.
2. Click "Duplica" on an invoice that has a customer, a note, a non-default language, and at least one row.
3. Confirm the New Invoice form opens with that customer selected, the note filled in, the language selected, and the same rows (description/price/VAT) populated.
4. Confirm the Number field shows the next available number (not the source invoice's number) and the Date field shows today's date.
5. Confirm the "Paid" checkbox is unchecked regardless of the source invoice's paid status.
6. Save the form and confirm a new invoice is created (distinct `number`) without altering the source invoice.
7. Click "Duplica" is not shown to break when clicked from a fresh invoices list load (i.e. normal "New invoice" button still opens a blank form).

- [ ] **Step 5: Run the full invoice test suite**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: PASS (no regressions from the template/prop changes).

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/invoices/Create.vue resources/js/pages/invoices/Index.vue
git commit -m "feat: add Duplica action to prefill the new invoice form"
```
