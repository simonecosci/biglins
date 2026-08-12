# Invoice HTML Preview & Multilingual PDF Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a logged-in user preview an invoice as HTML and download it as a PDF, in Italian, English, or Spanish, via a single Blade template the user edits directly to insert their own company data and logo.

**Architecture:** Add a `language` column to `invoices` (default `es`). A single Blade view (`resources/views/invoices/template.blade.php`) renders the full invoice document — company header (from `config/company.php` + logo as an embedded base64 data URI), customer block, rows table, totals, and notes last. Two new `InvoiceController` actions serve this same view: `preview` returns it as plain HTML, `pdf` pipes it through `barryvdh/laravel-dompdf` and streams a download. Labels come from `resources/lang/{it,en,es}/invoice.php`, selected via `App::setLocale($invoice->language)` before rendering.

**Tech Stack:** Laravel 13 (PHP 8.5), `barryvdh/laravel-dompdf`, Blade, Pest 5, Inertia v3 + Vue 3, Laravel Wayfinder.

## Global Constraints

- This plan builds directly on the already-implemented invoices CRUD (`app/Models/Invoice.php`, `app/Models/InvoiceRow.php`, `app/Http/Controllers/InvoiceController.php`, `routes/invoices.php`, `resources/js/pages/invoices/*.vue`) — do not re-create these, only modify them as instructed.
- `language` is one of `it`, `en`, `es`; default `es`; always required and validated server-side.
- The Blade template must render identically (same file, same output) whether called from `preview` (HTML) or `pdf` (dompdf) — no dompdf-only or browser-only branches.
- The logo is embedded as a base64 data URI at render time, not linked by URL — this is what makes the same template work in both a real browser and dompdf without a live HTTP server.
- Notes render last, after the totals block, and only when `$invoice->note` is non-empty.
- Company/issuer data lives in `config/company.php` only — no database table, no settings UI.
- Explicit return type declarations and param type hints on all PHP methods (project rule).
- Run `vendor/bin/pint --dirty --format agent` after any PHP change, before considering a task done.
- Run `php artisan test --compact --filter=Invoice` after each backend task.
- Frontend tasks: run `npm run dev` (or `composer run dev`) and manually exercise the page in a browser before considering the task done (no JS test runner in this repo).

---

### Task 1: `language` column, company config, model, factory, and validation

**Files:**
- Create: `database/migrations/2026_08_12_100000_add_language_to_invoices_table.php`
- Create: `config/company.php`
- Modify: `app/Models/Invoice.php:15-28` (docblock + `#[Fillable(...)]`)
- Modify: `database/factories/InvoiceFactory.php:19-28`
- Modify: `app/Http/Requests/StoreInvoiceRequest.php`
- Modify: `app/Http/Requests/UpdateInvoiceRequest.php`
- Test: `tests/Feature/InvoiceTest.php` (append + fix two existing tests)

**Interfaces:**
- Produces: `invoices.language` column (`string(2)`, default `es`); `Invoice::$fillable` includes `language`; `config('company.name'|'tax_id'|'address'|'zip'|'city'|'country'|'email'|'phone'|'iban'|'logo')`; `StoreInvoiceRequest`/`UpdateInvoiceRequest` validate `language` as `required|string|in:it,en,es`.
- Consumes: nothing new — extends the existing `Invoice` model, `InvoiceFactory`, and form requests from the invoices CRUD.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/InvoiceTest.php`, update the payload in the existing `'invoice can be created with rows'` test (around line 127) to include a language:

```php
test('invoice can be created with rows', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'paid' => false,
        'customer_id' => $customer->id,
        'note' => 'Test note',
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
            ['description' => 'Hosting', 'price' => 50, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('invoices.index'));

    $invoice = Invoice::query()->where('customer_id', $customer->id)->firstOrFail();
    expect($invoice->rows)->toHaveCount(2);
    expect($invoice->number)->not->toBeNull();
    expect($invoice->language)->toBe('en');
});
```

Update the existing `'updating an invoice syncs its rows: adds, updates and removes'` test (around line 193) to include the invoice's own language in the payload:

```php
test('updating an invoice syncs its rows: adds, updates and removes', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create();
    $keepRow = InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Keep me',
        'price' => 10,
        'vat_rate' => 22,
    ]);
    $removeRow = InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->put(route('invoices.update', $invoice), [
        'number' => $invoice->number,
        'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
        'paid' => true,
        'customer_id' => $invoice->customer_id,
        'language' => $invoice->language,
        'rows' => [
            ['id' => $keepRow->id, 'description' => 'Updated description', 'price' => 20, 'vat_rate' => 10],
            ['description' => 'New row', 'price' => 30, 'vat_rate' => 4],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('invoices.index'));

    $invoice->refresh();
    expect($invoice->paid)->toBeTrue();
    expect($invoice->rows)->toHaveCount(2);
    expect(InvoiceRow::query()->find($removeRow->id))->toBeNull();
    expect($keepRow->fresh()->description)->toBe('Updated description');
});
```

Append these new tests at the end of the file:

```php
test('invoice factory produces a valid language', function () {
    $invoice = Invoice::factory()->create();

    expect(['it', 'en', 'es'])->toContain($invoice->language);
});

test('invoice requires a language', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => '',
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('language');
});

test('invoice language must be it, en, or es', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'fr',
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('language');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: FAIL — `language` column doesn't exist yet and the form requests don't validate it, so `invoice can be created with rows` fails the new `expect($invoice->language)->toBe('en')` assertion (or errors because the column is missing), and the two new `language` validation tests fail because no `language` error is raised.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_12_100000_add_language_to_invoices_table.php`:

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
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('language', 2)->default('es')->after('note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
```

- [ ] **Step 4: Create the company config file**

Create `config/company.php`:

```php
<?php

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
    'logo' => 'images/logo.png',
];
```

- [ ] **Step 5: Add `language` to the `Invoice` model**

In `app/Models/Invoice.php`, update the docblock and the `#[Fillable(...)]` attribute:

```php
/**
 * @property string $id
 * @property string $number
 * @property Carbon $invoice_date
 * @property bool $paid
 * @property string $customer_id
 * @property string|null $note
 * @property string $language
 * @property-read float $subtotal
 * @property-read float $vat_total
 * @property-read float $total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['number', 'invoice_date', 'paid', 'customer_id', 'note', 'language'])]
class Invoice extends Model
```

- [ ] **Step 6: Add `language` to the invoice factory**

In `database/factories/InvoiceFactory.php`, update `definition()`:

```php
public function definition(): array
{
    return [
        'number' => null,
        'invoice_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
        'paid' => fake()->boolean(),
        'customer_id' => Customer::factory(),
        'note' => fake()->optional()->sentence(),
        'language' => fake()->randomElement(['it', 'en', 'es']),
    ];
}
```

- [ ] **Step 7: Validate `language` in the form requests**

In `app/Http/Requests/StoreInvoiceRequest.php`, add the `Rule` import and the `language` rule:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'number' => ['nullable', 'string', 'max:20', 'unique:invoices,number'],
            'invoice_date' => ['required', 'date'],
            'paid' => ['boolean'],
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'note' => ['nullable', 'string'],
            'language' => ['required', 'string', Rule::in(['it', 'en', 'es'])],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
```

In `app/Http/Requests/UpdateInvoiceRequest.php`, add the same rule:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'number' => [
                'nullable', 'string', 'max:20',
                Rule::unique('invoices', 'number')->ignore($this->route('invoice')),
            ],
            'invoice_date' => ['required', 'date'],
            'paid' => ['boolean'],
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'note' => ['nullable', 'string'],
            'language' => ['required', 'string', Rule::in(['it', 'en', 'es'])],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.id' => ['nullable', 'uuid', 'exists:invoice_rows,id'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: PASS (all tests in `tests/Feature/InvoiceTest.php`)

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_12_100000_add_language_to_invoices_table.php config/company.php app/Models/Invoice.php database/factories/InvoiceFactory.php app/Http/Requests/StoreInvoiceRequest.php app/Http/Requests/UpdateInvoiceRequest.php tests/Feature/InvoiceTest.php
git commit -m "feat: add invoice language field and company config"
```

---

### Task 2: Translations and the invoice Blade template

**Files:**
- Create: `resources/lang/it/invoice.php`
- Create: `resources/lang/en/invoice.php`
- Create: `resources/lang/es/invoice.php`
- Create: `resources/views/invoices/template.blade.php`
- Test: `tests/Feature/InvoiceTemplateTest.php`

**Interfaces:**
- Consumes: `Invoice` model with `language`, `note`, `rows` (each with `description`, `price`, `vat_rate`, `total`), `customer` (with `name`, `address`, `zip`, `city`, `nif`, and `country.name`) from Task 1 and the existing invoices CRUD; `config('company.*')` from Task 1.
- Produces: Blade view `invoices.template`, renderable via `view('invoices.template', ['invoice' => $invoice])->render()`. The caller is responsible for calling `App::setLocale($invoice->language)` before rendering/instantiating the view (the view itself does not change the locale) — consumed by Task 3's controller actions.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/InvoiceTemplateTest.php`:

```php
<?php

use App\Models\Invoice;
use App\Models\InvoiceRow;
use Illuminate\Support\Facades\App;

function renderInvoiceTemplate(Invoice $invoice): string
{
    App::setLocale($invoice->language);

    return view('invoices.template', [
        'invoice' => $invoice->load(['customer.country', 'rows']),
    ])->render();
}

test('template renders company data, customer data and rows', function () {
    $invoice = Invoice::factory()->create(['language' => 'en', 'note' => null]);
    InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Consulting work',
        'price' => 100,
        'vat_rate' => 22,
    ]);

    $html = renderInvoiceTemplate($invoice);

    expect($html)->toContain('Invoice');
    expect($html)->toContain($invoice->number);
    expect($html)->toContain($invoice->customer->name);
    expect($html)->toContain('Consulting work');
});

test('template labels switch per invoice language', function () {
    $it = Invoice::factory()->create(['language' => 'it']);
    $es = Invoice::factory()->create(['language' => 'es']);
    InvoiceRow::factory()->create(['invoice_id' => $it->id]);
    InvoiceRow::factory()->create(['invoice_id' => $es->id]);

    expect(renderInvoiceTemplate($it))->toContain('Fattura');
    expect(renderInvoiceTemplate($es))->toContain('Factura');
});

test('template shows the note after the totals section, at the bottom', function () {
    $invoice = Invoice::factory()->create(['language' => 'en', 'note' => 'Thank you for your business']);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $html = renderInvoiceTemplate($invoice);

    $totalPosition = strpos($html, 'Total');
    $notePosition = strpos($html, 'Thank you for your business');

    expect($totalPosition)->not->toBeFalse();
    expect($notePosition)->not->toBeFalse();
    expect($notePosition)->toBeGreaterThan($totalPosition);
});

test('template omits the notes section when the invoice has no note', function () {
    $invoice = Invoice::factory()->create(['language' => 'en', 'note' => null]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $html = renderInvoiceTemplate($invoice);

    expect($html)->not->toContain('id="notes"');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=InvoiceTemplateTest`
Expected: FAIL — `view('invoices.template', ...)` throws because the view doesn't exist yet.

- [ ] **Step 3: Create the translation files**

Create `resources/lang/it/invoice.php`:

```php
<?php

return [
    'title' => 'Fattura',
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

Create `resources/lang/en/invoice.php`:

```php
<?php

return [
    'title' => 'Invoice',
    'number' => 'Number',
    'date' => 'Date',
    'customer' => 'Customer',
    'description' => 'Description',
    'price' => 'Price',
    'vat' => 'VAT',
    'subtotal' => 'Subtotal',
    'total' => 'Total',
    'note' => 'Notes',
    'paid' => 'Paid',
    'unpaid' => 'Unpaid',
    'tax_id' => 'Tax ID',
    'iban' => 'IBAN',
];
```

Create `resources/lang/es/invoice.php`:

```php
<?php

return [
    'title' => 'Factura',
    'number' => 'Número',
    'date' => 'Fecha',
    'customer' => 'Cliente',
    'description' => 'Descripción',
    'price' => 'Precio',
    'vat' => 'IVA',
    'subtotal' => 'Subtotal',
    'total' => 'Total',
    'note' => 'Notas',
    'paid' => 'Pagada',
    'unpaid' => 'No pagada',
    'tax_id' => 'NIF',
    'iban' => 'IBAN',
];
```

- [ ] **Step 4: Create the invoice template**

Create `resources/views/invoices/template.blade.php`:

```blade
@php
    $logoPath = public_path(config('company.logo'));
    $logoData = file_exists($logoPath)
        ? 'data:' . mime_content_type($logoPath) . ';base64,' . base64_encode(file_get_contents($logoPath))
        : null;
@endphp
<!DOCTYPE html>
<html lang="{{ $invoice->language }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('invoice.title') }} {{ $invoice->number }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1f2937; margin: 40px; }
        table { border-collapse: collapse; }
        .header { width: 100%; margin-bottom: 30px; }
        .header td { vertical-align: top; }
        .logo { max-width: 160px; max-height: 80px; }
        .company { font-size: 11px; line-height: 1.5; margin-top: 8px; }
        .company strong { font-size: 14px; }
        .meta { text-align: right; }
        .meta h1 { font-size: 20px; margin: 0 0 8px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; }
        .badge-paid { background: #dcfce7; color: #166534; }
        .badge-unpaid { background: #fee2e2; color: #991b1b; }
        .customer { margin-bottom: 20px; }
        .customer h2 { font-size: 11px; text-transform: uppercase; color: #6b7280; margin: 0 0 4px; }
        table.rows { width: 100%; margin-bottom: 20px; }
        table.rows th { text-align: left; border-bottom: 2px solid #1f2937; padding: 6px 4px; font-size: 11px; text-transform: uppercase; }
        table.rows td { border-bottom: 1px solid #e5e7eb; padding: 6px 4px; }
        table.rows td.num, table.rows th.num { text-align: right; }
        table.totals { width: 260px; margin-left: auto; }
        table.totals td { padding: 4px; }
        table.totals td.num { text-align: right; }
        table.totals tr.total td { font-weight: bold; font-size: 14px; border-top: 2px solid #1f2937; }
        .notes { margin-top: 40px; padding-top: 12px; border-top: 1px solid #e5e7eb; }
        .notes h2 { font-size: 11px; text-transform: uppercase; color: #6b7280; margin: 0 0 4px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                @if($logoData)
                    <img class="logo" src="{{ $logoData }}" alt="{{ config('company.name') }}">
                @endif
                <div class="company">
                    <strong>{{ config('company.name') }}</strong><br>
                    {{ config('company.address') }}<br>
                    {{ config('company.zip') }} {{ config('company.city') }}, {{ config('company.country') }}<br>
                    {{ __('invoice.tax_id') }}: {{ config('company.tax_id') }}<br>
                    {{ config('company.email') }} &mdash; {{ config('company.phone') }}
                </div>
            </td>
            <td class="meta">
                <h1>{{ __('invoice.title') }}</h1>
                <div>{{ __('invoice.number') }}: {{ $invoice->number }}</div>
                <div>{{ __('invoice.date') }}: {{ $invoice->invoice_date->format('d/m/Y') }}</div>
                <div>
                    <span class="badge {{ $invoice->paid ? 'badge-paid' : 'badge-unpaid' }}">
                        {{ $invoice->paid ? __('invoice.paid') : __('invoice.unpaid') }}
                    </span>
                </div>
            </td>
        </tr>
    </table>

    <div class="customer">
        <h2>{{ __('invoice.customer') }}</h2>
        <div>
            <strong>{{ $invoice->customer->name }}</strong><br>
            @if($invoice->customer->address)
                {{ $invoice->customer->address }}<br>
            @endif
            @if($invoice->customer->zip || $invoice->customer->city)
                {{ $invoice->customer->zip }} {{ $invoice->customer->city }}<br>
            @endif
            @if($invoice->customer->country)
                {{ $invoice->customer->country->name }}<br>
            @endif
            @if($invoice->customer->nif)
                {{ __('invoice.tax_id') }}: {{ $invoice->customer->nif }}<br>
            @endif
        </div>
    </div>

    <table class="rows">
        <thead>
            <tr>
                <th>{{ __('invoice.description') }}</th>
                <th class="num">{{ __('invoice.price') }}</th>
                <th class="num">{{ __('invoice.vat') }}</th>
                <th class="num">{{ __('invoice.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->rows as $row)
                <tr>
                    <td>{{ $row->description }}</td>
                    <td class="num">{{ number_format((float) $row->price, 2) }}</td>
                    <td class="num">{{ number_format((float) $row->vat_rate, 2) }}%</td>
                    <td class="num">{{ number_format((float) $row->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>{{ __('invoice.subtotal') }}</td>
            <td class="num">{{ number_format($invoice->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>{{ __('invoice.vat') }}</td>
            <td class="num">{{ number_format($invoice->vat_total, 2) }}</td>
        </tr>
        <tr class="total">
            <td>{{ __('invoice.total') }}</td>
            <td class="num">{{ number_format($invoice->total, 2) }}</td>
        </tr>
    </table>

    @if($invoice->note)
        <div id="notes" class="notes">
            <h2>{{ __('invoice.note') }}</h2>
            <div>{{ $invoice->note }}</div>
        </div>
    @endif
</body>
</html>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=InvoiceTemplateTest`
Expected: PASS (all tests in `tests/Feature/InvoiceTemplateTest.php`)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/lang/it/invoice.php resources/lang/en/invoice.php resources/lang/es/invoice.php resources/views/invoices/template.blade.php tests/Feature/InvoiceTemplateTest.php
git commit -m "feat: add multilingual invoice document template"
```

---

### Task 3: PDF generation, controller actions, and routes

**Files:**
- Modify: `composer.json` / `composer.lock` (via `composer require`)
- Modify: `app/Http/Controllers/InvoiceController.php`
- Modify: `routes/invoices.php`
- Generate (via command): `resources/js/actions/App/Http/Controllers/InvoiceController.ts`, `resources/js/routes/invoices/index.ts`
- Test: `tests/Feature/InvoiceTest.php` (append)

**Interfaces:**
- Consumes: `invoices.template` Blade view (Task 2); `Invoice::language` (Task 1).
- Produces: named routes `invoices.preview` (`GET /invoices/{invoice}/preview`) and `invoices.pdf` (`GET /invoices/{invoice}/pdf`), both behind the existing `auth`+`verified` middleware group; regenerated Wayfinder helpers `InvoiceController.preview(id)` / `InvoiceController.pdf(id)` and `index`/`create`/`edit` unchanged — consumed by Tasks 4 and 5.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/InvoiceTest.php`:

```php
test('guests are redirected to the login page when previewing an invoice', function () {
    $invoice = Invoice::factory()->create();

    $this->get(route('invoices.preview', $invoice))->assertRedirect(route('login'));
});

test('invoice preview renders the invoice as html', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create(['language' => 'en', 'note' => 'Please pay within 30 days']);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'description' => 'Design work']);

    $response = $this->actingAs($user)->get(route('invoices.preview', $invoice));

    $response->assertOk();
    $response->assertSee($invoice->number);
    $response->assertSee($invoice->customer->name);
    $response->assertSee('Design work');
    $response->assertSee('Please pay within 30 days');
});

test('invoice pdf downloads as a pdf file', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create();
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain($invoice->number);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: FAIL — routes `invoices.preview` and `invoices.pdf` don't exist yet.

- [ ] **Step 3: Install the PDF package**

Run: `composer require barryvdh/laravel-dompdf --no-interaction`

- [ ] **Step 4: Add the controller actions**

In `app/Http/Controllers/InvoiceController.php`, add the imports (note: `Inertia\Response` is already imported as `Response`, so alias `Illuminate\Http\Response`):

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Customer;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
```

Add the two new methods, after `destroy()` and before the closing `}` of the class:

```php
    public function preview(Invoice $invoice): View
    {
        App::setLocale($invoice->language);

        return view('invoices.template', [
            'invoice' => $invoice->load(['customer.country', 'rows']),
        ]);
    }

    public function pdf(Invoice $invoice): HttpResponse
    {
        App::setLocale($invoice->language);

        return Pdf::loadView('invoices.template', [
            'invoice' => $invoice->load(['customer.country', 'rows']),
        ])->download("{$invoice->number}.pdf");
    }
```

- [ ] **Step 5: Add the routes**

In `routes/invoices.php`, add the two GET routes before the resource route:

```php
<?php

use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('invoices/{invoice}/preview', [InvoiceController::class, 'preview'])->name('invoices.preview');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::resource('invoices', InvoiceController::class)->except('show');
});
```

- [ ] **Step 6: Regenerate Wayfinder**

Run: `php artisan wayfinder:generate`

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: PASS (all tests in `tests/Feature/InvoiceTest.php`)

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock app/Http/Controllers/InvoiceController.php routes/invoices.php resources/js/actions/App/Http/Controllers/InvoiceController.ts resources/js/routes/invoices tests/Feature/InvoiceTest.php
git commit -m "feat: add invoice preview and pdf download routes"
```

---

### Task 4: Language field on the Create/Edit forms

**Files:**
- Modify: `resources/js/pages/invoices/Create.vue`
- Modify: `resources/js/pages/invoices/Edit.vue`

**Interfaces:**
- Consumes: `Select`/`SelectContent`/`SelectItem`/`SelectTrigger`/`SelectValue` from `@/components/ui/select` (already imported in both files); `StoreInvoiceRequest`/`UpdateInvoiceRequest`'s `language` rule (Task 1).
- Produces: both forms now submit a `language` field (`'it' | 'en' | 'es'`) alongside the existing fields.

- [ ] **Step 1: Add the language field to Create.vue**

In `resources/js/pages/invoices/Create.vue`, add `language: 'es'` to the `useForm()` call:

```ts
const form = useForm({
    number: props.nextNumber,
    invoice_date: new Date().toISOString().slice(0, 10),
    paid: false,
    customer_id: '',
    note: '',
    language: 'es',
    rows: [{ description: '', price: 0, vat_rate: 0 }] as InvoiceRowForm[],
});
```

Add a "Language" select right after the customer `<Select>` block (after its closing `</div>`, before the "Paid" checkbox block):

```vue
            <div class="grid gap-2">
                <Label for="language">Language</Label>
                <Select v-model="form.language">
                    <SelectTrigger id="language" class="w-full">
                        <SelectValue placeholder="Select a language" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="it">Italiano</SelectItem>
                        <SelectItem value="en">English</SelectItem>
                        <SelectItem value="es">Español</SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.language" />
            </div>
```

- [ ] **Step 2: Add the language field to Edit.vue**

In `resources/js/pages/invoices/Edit.vue`, add `language: string;` to the `Invoice` type:

```ts
type Invoice = {
    id: string;
    number: string;
    invoice_date: string;
    paid: boolean;
    customer_id: string;
    note: string | null;
    language: string;
    rows: InvoiceRow[];
};
```

Add `language: props.invoice.language` to the `useForm()` call:

```ts
const form = useForm({
    number: props.invoice.number,
    invoice_date: props.invoice.invoice_date,
    paid: props.invoice.paid,
    customer_id: props.invoice.customer_id,
    note: props.invoice.note ?? '',
    language: props.invoice.language,
    rows: props.invoice.rows.map((row) => ({
        id: row.id,
        description: row.description,
        price: row.price,
        vat_rate: row.vat_rate,
    })) as InvoiceRowForm[],
});
```

Add the same "Language" select block used in Create.vue, in the same position (after the customer `<Select>` block, before the "Paid" checkbox block):

```vue
            <div class="grid gap-2">
                <Label for="language">Language</Label>
                <Select v-model="form.language">
                    <SelectTrigger id="language" class="w-full">
                        <SelectValue placeholder="Select a language" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="it">Italiano</SelectItem>
                        <SelectItem value="en">English</SelectItem>
                        <SelectItem value="es">Español</SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.language" />
            </div>
```

- [ ] **Step 3: Verify in the browser**

Run: `npm run dev` (or `composer run dev` if it's not already running)
Visit `/invoices/create`: confirm the Language select defaults to "Español" and is changeable; submit and confirm the created invoice's language matches. Visit `/invoices/{id}/edit` for an existing invoice: confirm the Language select is prefilled with the invoice's current language; change it, save, and confirm it persists.

- [ ] **Step 4: Run the backend tests to confirm nothing broke**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/invoices/Create.vue resources/js/pages/invoices/Edit.vue
git commit -m "feat: add language select to invoice create and edit forms"
```

---

### Task 5: Preview and PDF links on the Index and Edit pages

**Files:**
- Modify: `resources/js/pages/invoices/Index.vue`
- Modify: `resources/js/pages/invoices/Edit.vue`

**Interfaces:**
- Consumes: `InvoiceController.preview(id).url` and `InvoiceController.pdf(id).url` from `@/actions/App/Http/Controllers/InvoiceController` (Task 3).

- [ ] **Step 1: Add the links to Index.vue**

In `resources/js/pages/invoices/Index.vue`, import the controller actions:

```ts
import InvoiceController from '@/actions/App/Http/Controllers/InvoiceController';
```

Add this import alongside the existing `@/routes/invoices` import (order doesn't matter for the app to work, but keep alphabetical grouping consistent with the rest of the file's imports).

In the row actions cell, add "Preview" and "PDF" links before the existing "Edit" link:

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
                        </td>
```

- [ ] **Step 2: Add the links to Edit.vue**

In `resources/js/pages/invoices/Edit.vue`, add the same two links near the page heading, right after the closing `</Heading>`-generating call (i.e. right after the `<Heading ... />` element, before the `<form>`):

```vue
        <Heading
            title="Edit invoice"
            :description="`Update invoice ${invoice.number}`"
        />

        <div class="flex gap-4">
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
                Download PDF
            </a>
        </div>
```

(`InvoiceController` is already imported in `Edit.vue` for the existing `.update()`/`.destroy()` calls, so no new import is needed there.)

- [ ] **Step 3: Verify in the browser**

Run: `npm run dev` (or `composer run dev` if it's not already running)
Visit `/invoices`: click "Preview" on a row and confirm it opens the invoice as an HTML page in a new tab, with the notes appearing after the totals; click "PDF" and confirm a `.pdf` file downloads. Visit `/invoices/{id}/edit`: confirm the same two links work from the edit page. Change an invoice's language on the edit page, save, then preview it again and confirm the labels switched language.

- [ ] **Step 4: Run the full invoice test suite**

Run: `php artisan test --compact --filter=Invoice`
Expected: PASS (all tests in `tests/Feature/InvoiceTest.php` and `tests/Feature/InvoiceTemplateTest.php`)

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/invoices/Index.vue resources/js/pages/invoices/Edit.vue
git commit -m "feat: add invoice preview and pdf download links"
```
