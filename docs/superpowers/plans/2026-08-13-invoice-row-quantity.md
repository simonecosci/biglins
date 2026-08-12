# Invoice Row Quantity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `quantity` field to invoice rows so a row's total is `price * quantity` (plus VAT), across the database, backend validation, the create/edit forms, and the PDF/preview template.

**Architecture:** Add a `decimal(10,2)` `quantity` column to `invoice_rows` (default `1.00`). `InvoiceRow::total()` and `Invoice::subtotal()`/`vatTotal()` multiply `price` by `quantity` before applying VAT. `StoreInvoiceRequest`/`UpdateInvoiceRequest` require `rows.*.quantity` to be a positive number. `InvoiceController::create()`'s duplicate-mapping array carries `quantity` through. The Create/Edit Vue forms gain a "Quantity" input per row and their client-side running total accounts for it. The Blade invoice template gains a "Quantity" column; its existing "Total" column already reflects the new calculation via `$row->total`.

**Tech Stack:** Laravel 13 (PHP 8.5), Pest 5, Inertia v3 + Vue 3, Laravel Wayfinder, Blade.

## Global Constraints

- This plan builds directly on the already-implemented invoices CRUD and PDF/preview feature (`app/Models/Invoice.php`, `app/Models/InvoiceRow.php`, `app/Http/Controllers/InvoiceController.php`, `resources/js/pages/invoices/*.vue`, `resources/views/invoices/template.blade.php`) — do not re-create these, only modify them as instructed.
- `quantity` is a `decimal(10,2)`, always `> 0` (`min:0.01`), default `1.00` so existing rows are unaffected.
- The `invoice_rows` table migration already ran elsewhere — add a new migration, do not edit the existing `2026_08_12_090100_create_invoice_rows_table.php`.
- Explicit return type declarations and param type hints on all PHP methods (project rule).
- Run `vendor/bin/pint --dirty --format agent` after any PHP change, before considering a task done.
- Run `php artisan test --compact --filter=Invoice` after each backend task.
- Frontend tasks: run `npm run dev` (or `composer run dev`) and manually exercise the page in a browser before considering the task done (no JS test runner in this repo).

---

### Task 1: `quantity` column, model accessors, and factory

**Files:**
- Create: `database/migrations/2026_08_13_090000_add_quantity_to_invoice_rows_table.php`
- Modify: `app/Models/InvoiceRow.php:14-59`
- Modify: `app/Models/Invoice.php:104-123`
- Modify: `database/factories/InvoiceRowFactory.php:19-27`
- Test: `tests/Feature/InvoiceTest.php:85-99` (update 2 existing tests, add 2 new ones)

**Interfaces:**
- Produces: `invoice_rows.quantity` column (`decimal(10,2)`, default `1.00`); `InvoiceRow::$fillable` includes `quantity`; `InvoiceRow->quantity` cast to `float`; `InvoiceRow->total` = `price * quantity + price * quantity * vat_rate / 100`; `Invoice->subtotal` = `sum(price * quantity)`; `Invoice->vat_total` = `sum(price * quantity * vat_rate / 100)`.
- Consumes: nothing new — extends the existing `InvoiceRow`/`Invoice` models and `InvoiceRowFactory`.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/InvoiceTest.php`, replace the existing `'invoice total accessors sum its rows'` and `'invoice row total accessor adds vat to the price'` tests (lines 85-99) with:

```php
test('invoice total accessors sum its rows', function () {
    $invoice = Invoice::factory()->create();
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => 100, 'vat_rate' => 22]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => 50, 'vat_rate' => 10]);

    expect((float) $invoice->subtotal)->toEqual(150.0);
    expect((float) $invoice->vat_total)->toEqual(27.0);
    expect((float) $invoice->total)->toEqual(177.0);
});

test('invoice total accessors multiply price by quantity per row', function () {
    $invoice = Invoice::factory()->create();
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 2, 'price' => 100, 'vat_rate' => 22]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 3, 'price' => 10, 'vat_rate' => 0]);

    expect((float) $invoice->subtotal)->toEqual(230.0);
    expect((float) $invoice->vat_total)->toEqual(44.0);
    expect((float) $invoice->total)->toEqual(274.0);
});

test('invoice row total accessor adds vat to the price', function () {
    $row = InvoiceRow::factory()->create(['quantity' => 1, 'price' => 100, 'vat_rate' => 22]);

    expect((float) $row->total)->toEqual(122.0);
});

test('invoice row total accessor multiplies price by quantity before applying vat', function () {
    $row = InvoiceRow::factory()->create(['quantity' => 2, 'price' => 100, 'vat_rate' => 22]);

    expect((float) $row->total)->toEqual(244.0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: FAIL — `quantity` isn't fillable/cast yet, so the factory silently drops it, rows fall back to the DB default (once the column exists) or the assertions relying on quantity multiplication (2x, 3x) produce wrong totals.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_13_090000_add_quantity_to_invoice_rows_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoice_rows', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->default(1)->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_rows', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};
```

- [ ] **Step 4: Update the `InvoiceRow` model**

In `app/Models/InvoiceRow.php`, update the docblock, the `#[Fillable(...)]` attribute, `casts()`, and `total()`:

```php
/**
 * @property string $id
 * @property string $invoice_id
 * @property string $description
 * @property float $quantity
 * @property float $price
 * @property float $vat_rate
 * @property-read float $total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['invoice_id', 'description', 'quantity', 'price', 'vat_rate'])]
class InvoiceRow extends Model
{
    /** @use HasFactory<InvoiceRowFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'price' => 'float',
            'vat_rate' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return Attribute<float, never>
     */
    protected function total(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                $lineTotal = (float) $this->price * (float) $this->quantity;

                return $lineTotal + $lineTotal * (float) $this->vat_rate / 100;
            },
        );
    }
}
```

- [ ] **Step 5: Update the `Invoice` model's totals**

In `app/Models/Invoice.php`, replace `subtotal()` and `vatTotal()` (lines 104-123):

```php
    /**
     * @return Attribute<float, never>
     */
    protected function subtotal(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->rows->sum(
                fn (InvoiceRow $row): float => (float) $row->price * (float) $row->quantity
            ),
        );
    }

    /**
     * @return Attribute<float, never>
     */
    protected function vatTotal(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->rows->sum(function (InvoiceRow $row): float {
                $lineTotal = (float) $row->price * (float) $row->quantity;

                return $lineTotal * (float) $row->vat_rate / 100;
            }),
        );
    }
```

- [ ] **Step 6: Update the invoice row factory**

In `database/factories/InvoiceRowFactory.php`, update `definition()`:

```php
public function definition(): array
{
    return [
        'invoice_id' => Invoice::factory(),
        'description' => fake()->sentence(4),
        'quantity' => fake()->numberBetween(1, 5),
        'price' => fake()->randomFloat(2, 10, 1000),
        'vat_rate' => fake()->randomElement([7, 0]),
    ];
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: PASS (all tests in `tests/Feature/InvoiceTest.php`)

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_13_090000_add_quantity_to_invoice_rows_table.php app/Models/InvoiceRow.php app/Models/Invoice.php database/factories/InvoiceRowFactory.php tests/Feature/InvoiceTest.php
git commit -m "feat: add quantity to invoice rows and multiply totals by it"
```

---

### Task 2: Validation, duplicate mapping, and HTTP feature tests

**Files:**
- Modify: `app/Http/Requests/StoreInvoiceRequest.php:28-31`
- Modify: `app/Http/Requests/UpdateInvoiceRequest.php:32-35`
- Modify: `app/Http/Controllers/InvoiceController.php:61-65`
- Test: `tests/Feature/InvoiceTest.php` (update 3 existing tests, add 2 new ones)

**Interfaces:**
- Consumes: `InvoiceRow::$fillable`/`quantity` cast (Task 1).
- Produces: `StoreInvoiceRequest`/`UpdateInvoiceRequest` validate `rows.*.quantity` as `required|numeric|min:0.01`; `InvoiceController::create()`'s `duplicate.rows.*` array includes `quantity`.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/InvoiceTest.php`, update the `'invoice can be created with rows'` test (around line 147) to send and assert `quantity`:

```php
test('invoice can be created with rows', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'paid' => false,
        'customer_id' => $customer->id,
        'company_id' => $company->id,
        'note' => 'Test note',
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 2, 'price' => 100, 'vat_rate' => 22],
            ['description' => 'Hosting', 'quantity' => 1, 'price' => 50, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('invoices.index'));

    $invoice = Invoice::query()->where('customer_id', $customer->id)->firstOrFail();
    expect($invoice->rows)->toHaveCount(2);
    expect($invoice->number)->not->toBeNull();
    expect($invoice->language)->toBe('en');
    expect($invoice->company_id)->toBe($company->id);
    expect($invoice->rows->firstWhere('description', 'Consulting')->quantity)->toEqual(2.0);
});
```

Insert these two new tests right after the `'invoice requires at least one row'` test (around line 187, before `'invoice customer_id must reference an existing customer'`):

```php
test('invoice row requires a quantity', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'company_id' => $company->id,
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('rows.0.quantity');
});

test('invoice row quantity must be greater than zero', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'company_id' => $company->id,
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 0, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('rows.0.quantity');
});
```

Update the `'updating an invoice syncs its rows: adds, updates and removes'` test (around line 255) to send and assert `quantity`:

```php
test('updating an invoice syncs its rows: adds, updates and removes', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create();
    $keepRow = InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Keep me',
        'quantity' => 1,
        'price' => 10,
        'vat_rate' => 22,
    ]);
    $removeRow = InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->put(route('invoices.update', $invoice), [
        'number' => $invoice->number,
        'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
        'paid' => true,
        'customer_id' => $invoice->customer_id,
        'company_id' => $invoice->company_id,
        'language' => $invoice->language,
        'rows' => [
            ['id' => $keepRow->id, 'description' => 'Updated description', 'quantity' => 3, 'price' => 20, 'vat_rate' => 10],
            ['description' => 'New row', 'quantity' => 1, 'price' => 30, 'vat_rate' => 4],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('invoices.index'));

    $invoice->refresh();
    expect($invoice->paid)->toBeTrue();
    expect($invoice->rows)->toHaveCount(2);
    expect(InvoiceRow::query()->find($removeRow->id))->toBeNull();
    expect($keepRow->fresh()->description)->toBe('Updated description');
    expect($keepRow->fresh()->quantity)->toEqual(3.0);
});
```

Update the `'invoice create page prefills from a duplicate query param'` test (around line 406) to set and assert `quantity`:

```php
test('invoice create page prefills from a duplicate query param', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();
    $source = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'company_id' => $company->id,
        'note' => 'Source note',
        'language' => 'en',
        'paid' => true,
    ]);
    InvoiceRow::factory()->create([
        'invoice_id' => $source->id,
        'description' => 'Consulting',
        'quantity' => 2,
        'price' => 100,
        'vat_rate' => 22,
    ]);

    $response = $this->actingAs($user)->get(route('invoices.create', ['duplicate' => $source->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate.customer_id', $customer->id)
        ->where('duplicate.company_id', $company->id)
        ->where('duplicate.note', 'Source note')
        ->where('duplicate.language', 'en')
        ->where('duplicate.rows.0.description', 'Consulting')
        ->where('duplicate.rows.0.quantity', 2)
        ->where('duplicate.rows.0.price', 100)
        ->where('duplicate.rows.0.vat_rate', 22)
        ->missing('duplicate.paid')
        ->missing('duplicate.rows.0.id')
    );
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: FAIL — `rows.*.quantity` has no validation rule yet, so it's stripped from validated input (rows persist with the DB default `1.00` instead of the submitted value, and `duplicate.rows.0.quantity` is never in the mapped array), and the two new validation tests find no `rows.0.quantity` error.

- [ ] **Step 3: Add the validation rule to both form requests**

In `app/Http/Requests/StoreInvoiceRequest.php`, update `rules()` (lines 28-31):

```php
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
```

In `app/Http/Requests/UpdateInvoiceRequest.php`, update `rules()` (lines 32-35):

```php
            'rows.*.id' => ['nullable', 'uuid', 'exists:invoice_rows,id'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
```

- [ ] **Step 4: Carry `quantity` through the duplicate mapping**

In `app/Http/Controllers/InvoiceController.php`, update the `rows` map inside `create()` (lines 61-65):

```php
                'rows' => $source->rows->map(fn (InvoiceRow $row): array => [
                    'description' => $row->description,
                    'quantity' => $row->quantity,
                    'price' => $row->price,
                    'vat_rate' => $row->vat_rate,
                ])->all(),
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: PASS (all tests in `tests/Feature/InvoiceTest.php`)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/StoreInvoiceRequest.php app/Http/Requests/UpdateInvoiceRequest.php app/Http/Controllers/InvoiceController.php tests/Feature/InvoiceTest.php
git commit -m "feat: validate and persist invoice row quantity"
```

---

### Task 3: Quantity field on the Create/Edit forms

**Files:**
- Modify: `resources/js/pages/invoices/Create.vue`
- Modify: `resources/js/pages/invoices/Edit.vue`

**Interfaces:**
- Consumes: `rows.*.quantity` validation (Task 2); `Input`/`InputError` components (already imported in both files).
- Produces: both forms submit `rows.*.quantity` (`number`) alongside the existing row fields; the client-side running `total` accounts for it.

- [ ] **Step 1: Update `Create.vue`**

In `resources/js/pages/invoices/Create.vue`, update the `InvoiceRowForm` type (lines 32-36):

```ts
type InvoiceRowForm = {
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
};
```

Update the default row in the `useForm()` call (line 68):

```ts
    rows: props.duplicate?.rows ?? [{ description: '', quantity: 1, price: 0, vat_rate: 0 }],
```

Update `addRow()` (lines 71-73):

```ts
function addRow(): void {
    form.rows.push({ description: '', quantity: 1, price: 0, vat_rate: 0 });
}
```

Update the `total` computed (lines 79-84) to multiply by quantity:

```ts
const total = computed(() =>
    form.rows.reduce((sum, row) => {
        const lineTotal = row.price * row.quantity;

        return sum + lineTotal + (lineTotal * row.vat_rate) / 100;
    }, 0),
);
```

Update the rows header (lines 204-211) to add a Quantity column and widen the grid:

```vue
                <div
                    class="grid grid-cols-[1fr_6rem_8rem_6rem_2.5rem] gap-2 text-sm text-muted-foreground"
                >
                    <span>Description</span>
                    <span>Quantity</span>
                    <span>Price</span>
                    <span>VAT (%)</span>
                    <span></span>
                </div>
```

Update the row template (lines 213-259) to match the new grid and add the Quantity input between Description and Price:

```vue
                <div
                    v-for="(row, i) in form.rows"
                    :key="i"
                    class="grid grid-cols-[1fr_6rem_8rem_6rem_2.5rem] items-start gap-2"
                >
                    <div class="grid gap-1">
                        <Input
                            v-model="row.description"
                            placeholder="Description"
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.description`]"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            v-model.number="row.quantity"
                            type="number"
                            step="0.01"
                            min="0.01"
                            placeholder="Quantity"
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.quantity`]"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            v-model.number="row.price"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="Price"
                        />
                        <InputError :message="form.errors[`rows.${i}.price`]" />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            v-model.number="row.vat_rate"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            placeholder="VAT %"
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.vat_rate`]"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="form.rows.length === 1"
                        @click="removeRow(i)"
                    >
                        <Trash2 />
                    </Button>
                </div>
```

- [ ] **Step 2: Update `Edit.vue`**

In `resources/js/pages/invoices/Edit.vue`, update the `InvoiceRow` type (lines 32-37):

```ts
type InvoiceRow = {
    id: string;
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
};
```

Update the `InvoiceRowForm` type (lines 51-56):

```ts
type InvoiceRowForm = {
    id?: string;
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
};
```

Update the `rows` mapping in the `useForm()` call (lines 80-85):

```ts
    rows: props.invoice.rows.map((row) => ({
        id: row.id,
        description: row.description,
        quantity: row.quantity,
        price: row.price,
        vat_rate: row.vat_rate,
    })) as InvoiceRowForm[],
```

Update `addRow()` (lines 88-90):

```ts
function addRow(): void {
    form.rows.push({ description: '', quantity: 1, price: 0, vat_rate: 0 });
}
```

Update the `total` computed (lines 96-101) to the same formula used in `Create.vue`:

```ts
const total = computed(() =>
    form.rows.reduce((sum, row) => {
        const lineTotal = row.price * row.quantity;

        return sum + lineTotal + (lineTotal * row.vat_rate) / 100;
    }, 0),
);
```

Update the rows header (lines 247-254) the same way as `Create.vue`:

```vue
                <div
                    class="grid grid-cols-[1fr_6rem_8rem_6rem_2.5rem] gap-2 text-sm text-muted-foreground"
                >
                    <span>Description</span>
                    <span>Quantity</span>
                    <span>Price</span>
                    <span>VAT (%)</span>
                    <span></span>
                </div>
```

Update the row template (lines 256-302), keeping the existing `:key="row.id ?? \`new-${i}\`"`:

```vue
                <div
                    v-for="(row, i) in form.rows"
                    :key="row.id ?? `new-${i}`"
                    class="grid grid-cols-[1fr_6rem_8rem_6rem_2.5rem] items-start gap-2"
                >
                    <div class="grid gap-1">
                        <Input
                            v-model="row.description"
                            placeholder="Description"
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.description`]"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            v-model.number="row.quantity"
                            type="number"
                            step="0.01"
                            min="0.01"
                            placeholder="Quantity"
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.quantity`]"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            v-model.number="row.price"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="Price"
                        />
                        <InputError :message="form.errors[`rows.${i}.price`]" />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            v-model.number="row.vat_rate"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            placeholder="VAT %"
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.vat_rate`]"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="form.rows.length === 1"
                        @click="removeRow(i)"
                    >
                        <Trash2 />
                    </Button>
                </div>
```

- [ ] **Step 3: Verify in the browser**

Run: `npm run dev` (or `composer run dev` if it's not already running)
Visit `/invoices/create`: confirm a "Quantity" column appears between Description and Price, defaults to `1`, and changing it updates the running total at the bottom. Visit `/invoices/{id}/edit` for an existing invoice: confirm each row's quantity is prefilled, editable, and the running total updates accordingly; save and confirm it persists.

- [ ] **Step 4: Run the backend tests to confirm nothing broke**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/invoices/Create.vue resources/js/pages/invoices/Edit.vue
git commit -m "feat: add quantity field to invoice create and edit forms"
```

---

### Task 4: Quantity column in the PDF/preview template

**Files:**
- Modify: `resources/lang/en/invoice.php`
- Modify: `resources/lang/it/invoice.php`
- Modify: `resources/lang/es/invoice.php`
- Modify: `resources/views/invoices/template.blade.php:80-99`
- Test: `tests/Feature/InvoiceTemplateTest.php`

**Interfaces:**
- Consumes: `InvoiceRow->quantity`, `InvoiceRow->total` (Task 1); `__('invoice.quantity')` translation key.
- Produces: the rendered invoice document (HTML and PDF, same Blade view) shows a "Quantity" column between Description and Price.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/InvoiceTemplateTest.php`:

```php
test('template renders the quantity column for each row', function () {
    $invoice = Invoice::factory()->create(['language' => 'en']);
    InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Consulting',
        'quantity' => 2,
        'price' => 100,
        'vat_rate' => 22,
    ]);

    $html = renderInvoiceTemplate($invoice);

    expect($html)->toContain('Quantity');
    expect($html)->toContain('2.00');
    expect($html)->toContain('244.00');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=InvoiceTemplateTest`
Expected: FAIL — there's no `quantity` translation key (so `__('invoice.quantity')` isn't rendered as "Quantity" anywhere useful) and no quantity cell in the rows table.

- [ ] **Step 3: Add the `quantity` translation key**

In `resources/lang/en/invoice.php`, add after `'description' => 'Description',`:

```php
    'quantity' => 'Quantity',
```

In `resources/lang/it/invoice.php`, add after `'description' => 'Descrizione',`:

```php
    'quantity' => 'Quantità',
```

In `resources/lang/es/invoice.php`, add after `'description' => 'Descripción',`:

```php
    'quantity' => 'Cantidad',
```

- [ ] **Step 4: Add the quantity column to the template**

In `resources/views/invoices/template.blade.php`, update the rows table (lines 80-99):

```blade
    <table class="rows">
        <thead>
            <tr>
                <th>{{ __('invoice.description') }}</th>
                <th class="num">{{ __('invoice.quantity') }}</th>
                <th class="num">{{ __('invoice.price') }}</th>
                <th class="num">{{ __('invoice.vat') }}</th>
                <th class="num">{{ __('invoice.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->rows as $row)
                <tr>
                    <td>{{ $row->description }}</td>
                    <td class="num">{{ number_format((float) $row->quantity, 2) }}</td>
                    <td class="num">{{ number_format((float) $row->price, 2) }}</td>
                    <td class="num">{{ number_format((float) $row->vat_rate, 2) }}%</td>
                    <td class="num">{{ number_format((float) $row->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=Invoice`
Expected: PASS (all tests in `tests/Feature/InvoiceTest.php` and `tests/Feature/InvoiceTemplateTest.php`)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/lang/en/invoice.php resources/lang/it/invoice.php resources/lang/es/invoice.php resources/views/invoices/template.blade.php tests/Feature/InvoiceTemplateTest.php
git commit -m "feat: display invoice row quantity in preview and pdf template"
```
