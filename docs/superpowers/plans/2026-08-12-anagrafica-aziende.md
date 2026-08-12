# Anagrafica Aziende (Company Registry) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `config/company.php` with a full CRUD `Company` registry on the database, let every invoice pick which company issues it (with logo upload), and update the project's GitHub wiki to match.

**Architecture:** New `companies` table + `Company` model, mirrored 1:1 on the existing `Customer`/`Country` CRUD pattern (resource controller, form requests, Inertia/Vue pages). `invoices.company_id` becomes a required FK (`restrictOnDelete`), following the same pattern already used for `invoices.customer_id`. The PDF/preview `Blade` template switches from `config('company.*')` to `$invoice->company->*`. Logos are uploaded straight into `public/images/companies/` (not the `storage` disk) to match how the template already resolves the logo path via `public_path()`.

**Tech Stack:** Laravel 13 (PHP 8.5), Inertia.js v3 + Vue 3 (TypeScript), Laravel Wayfinder, Tailwind CSS v4, Pest 5, DomPDF.

## Global Constraints

- Follow existing conventions exactly: `#[Fillable(...)]` attribute (not `$fillable`), `HasUuids`, explicit `@property` PHPDoc, explicit return types/type hints on every method.
- No new dependencies, no new top-level directories.
- Every backend change needs a Pest feature test; run the affected test file after each task.
- Run `vendor/bin/pint --dirty --format agent` before considering any task with PHP changes done.
- The local dev database will be wiped (`migrate:fresh`) — no data-migration/backfill logic is needed anywhere in this plan.
- Company field set mirrors `config/company.php`: `name`, `tax_id`, `address`, `zip`, `city`, `country` (as `country_id` FK, not free text), `email`, `phone`, `iban`, `logo`. Only `name` is required.
- Only one `Company` may have `is_default = true` at a time; the first company ever created becomes default automatically.
- Deleting a company that has invoices must be explicitly blocked with a flash error message (not just left to the DB FK exception).
- Logo upload is optional, validated as an image (`jpeg,png,jpg,svg,webp`, max 2MB), stored at `public/images/companies/{company_id}.{ext}`, replacing/removing must delete the old file from disk.

---

### Task 1: Company data layer (migration, model, factory)

**Files:**
- Create: `database/migrations/2026_08_12_120000_create_companies_table.php`
- Create: `app/Models/Company.php`
- Modify: `app/Models/Country.php`
- Create: `database/factories/CompanyFactory.php`
- Test: `tests/Feature/CompanyTest.php`

**Interfaces:**
- Produces: `App\Models\Company` with `HasUuids`, `belongsTo(Country::class)` as `country()`, `hasMany(Invoice::class)` as `invoices()` (the `invoices()` method is declared now even though `Invoice` has no `company_id` yet — it's wired in Task 2), casts `is_default` to `bool`. `Database\Factories\CompanyFactory` producing a valid random company (with a `Country` via `Country::factory()`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CompanyTest.php`:

```php
<?php

use App\Models\Company;
use App\Models\Country;

test('company factory creates a company belonging to a country', function () {
    $company = Company::factory()->create();

    expect($company->id)->toBeString();
    expect(strlen($company->id))->toBe(36);
    expect($company->country)->toBeInstanceOf(Country::class);
});

test('company can be created without a country', function () {
    $company = Company::factory()->create(['country_id' => null]);

    expect($company->country_id)->toBeNull();
    expect($company->country)->toBeNull();
});

test('company is_default defaults to false and casts to boolean', function () {
    $company = Company::factory()->create();

    expect($company->is_default)->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CompanyTest`
Expected: FAIL — `Class "App\Models\Company" not found` (or similar).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_12_120000_create_companies_table.php`:

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
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->string('address')->nullable();
            $table->string('zip')->nullable();
            $table->string('city')->nullable();
            $table->foreignUuid('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('iban')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/Company.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string|null $tax_id
 * @property string|null $address
 * @property string|null $zip
 * @property string|null $city
 * @property string|null $country_id
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $iban
 * @property string|null $logo
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'tax_id', 'address', 'zip', 'city', 'country_id', 'email', 'phone', 'iban', 'logo', 'is_default'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
```

- [ ] **Step 5: Add the reverse relation on `Country`**

Modify `app/Models/Country.php` — add `Illuminate\Database\Eloquent\Relations\HasMany` is already imported; add this method after `customers()`:

```php
    /**
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
```

- [ ] **Step 6: Create the factory**

Create `database/factories/CompanyFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'tax_id' => fake()->numerify('########'),
            'address' => fake()->streetAddress(),
            'zip' => fake()->postcode(),
            'city' => fake()->city(),
            'country_id' => Country::factory(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'iban' => 'ES'.fake()->numerify('##################'),
            'logo' => null,
            'is_default' => false,
        ];
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --compact --filter=CompanyTest`
Expected: PASS (3 tests)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_12_120000_create_companies_table.php app/Models/Company.php app/Models/Country.php database/factories/CompanyFactory.php tests/Feature/CompanyTest.php
git commit -m "feat: add Company model, migration and factory"
```

---

### Task 2: Wire Company into Invoices (backend)

**Files:**
- Create: `database/migrations/2026_08_12_120100_add_company_id_to_invoices_table.php`
- Modify: `app/Models/Invoice.php`
- Modify: `database/factories/InvoiceFactory.php`
- Modify: `app/Http/Requests/StoreInvoiceRequest.php`
- Modify: `app/Http/Requests/UpdateInvoiceRequest.php`
- Modify: `app/Http/Controllers/InvoiceController.php`
- Modify: `resources/views/invoices/template.blade.php`
- Modify: `tests/Feature/InvoiceTest.php`
- Modify: `tests/Feature/InvoiceTemplateTest.php`
- Delete: `config/company.php`
- Delete: `config/company.php.example`

**Interfaces:**
- Consumes: `App\Models\Company` (Task 1) — `Company::factory()`, `country()` relation.
- Produces: `Invoice::company()` (`BelongsTo<Company, $this>`), `Invoice::$company_id` fillable, `InvoiceController::create()`/`edit()` responses gain a `companies: {id, name}[]` prop and `create()` also gains `defaultCompanyId: string|null` and `duplicate.company_id`. `StoreInvoiceRequest`/`UpdateInvoiceRequest` require `company_id`. Later tasks (frontend, Task 6) consume these exact prop names.

- [ ] **Step 1: Update the test suites first (they will fail until the implementation lands)**

Replace the full contents of `tests/Feature/InvoiceTest.php` with:

```php
<?php

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

test('invoice factory creates an invoice with a uuid primary key', function () {
    $invoice = Invoice::factory()->create();

    expect($invoice->id)->toBeString();
    expect(strlen($invoice->id))->toBe(36);
    expect($invoice->customer)->toBeInstanceOf(Customer::class);
    expect($invoice->company)->toBeInstanceOf(Company::class);
});

test('first invoice of the year is numbered 0001', function () {
    Carbon::setTestNow('2026-01-15');

    $invoice = Invoice::factory()->create(['number' => null]);

    expect($invoice->number)->toBe('2026-0001');

    Carbon::setTestNow();
});

test('subsequent invoices in the same year increment the sequence', function () {
    Carbon::setTestNow('2026-01-15');

    Invoice::factory()->create(['number' => null]);
    $second = Invoice::factory()->create(['number' => null]);
    $third = Invoice::factory()->create(['number' => null]);

    expect($second->number)->toBe('2026-0002');
    expect($third->number)->toBe('2026-0003');

    Carbon::setTestNow();
});

test('a new calendar year resets the sequence to 0001', function () {
    Carbon::setTestNow('2026-12-31');
    Invoice::factory()->create(['number' => null]);

    Carbon::setTestNow('2027-01-01');
    $invoice = Invoice::factory()->create(['number' => null]);

    expect($invoice->number)->toBe('2027-0001');

    Carbon::setTestNow();
});

test('an explicitly provided number is respected instead of being generated', function () {
    $invoice = Invoice::factory()->create(['number' => '2026-9999']);

    expect($invoice->number)->toBe('2026-9999');
});

test('invoice number must be unique at the database level', function () {
    Invoice::factory()->create(['number' => '2026-0001']);

    expect(fn () => Invoice::factory()->create(['number' => '2026-0001']))
        ->toThrow(QueryException::class);
});

test('invoice has many rows and rows are deleted when the invoice is deleted', function () {
    $invoice = Invoice::factory()->create();
    InvoiceRow::factory()->count(2)->create(['invoice_id' => $invoice->id]);

    expect($invoice->rows)->toHaveCount(2);

    $invoice->delete();

    expect(InvoiceRow::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('a customer with invoices cannot be deleted', function () {
    $customer = Customer::factory()->create();
    Invoice::factory()->create(['customer_id' => $customer->id]);

    expect(fn () => $customer->delete())->toThrow(QueryException::class);
});

test('invoice total accessors sum its rows', function () {
    $invoice = Invoice::factory()->create();
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'price' => 100, 'vat_rate' => 22]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'price' => 50, 'vat_rate' => 10]);

    expect((float) $invoice->subtotal)->toEqual(150.0);
    expect((float) $invoice->vat_total)->toEqual(27.0);
    expect((float) $invoice->total)->toEqual(177.0);
});

test('invoice row total accessor adds vat to the price', function () {
    $row = InvoiceRow::factory()->create(['price' => 100, 'vat_rate' => 22]);

    expect((float) $row->total)->toEqual(122.0);
});

use App\Models\User;
use Illuminate\Support\Str;

test('guests are redirected to the login page when visiting invoices', function () {
    $this->get(route('invoices.index'))->assertRedirect(route('login'));
});

test('invoices index page can be rendered', function () {
    $user = User::factory()->create();
    Invoice::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('invoices.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('invoices/Index'));
});

test('invoice create page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('invoices.create'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->has('nextNumber')
        ->has('companies')
    );
});

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
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
            ['description' => 'Hosting', 'price' => 50, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('invoices.index'));

    $invoice = Invoice::query()->where('customer_id', $customer->id)->firstOrFail();
    expect($invoice->rows)->toHaveCount(2);
    expect($invoice->number)->not->toBeNull();
    expect($invoice->language)->toBe('en');
    expect($invoice->company_id)->toBe($company->id);
});

test('invoice requires at least one row', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'company_id' => $company->id,
        'rows' => [],
    ]);

    $response->assertSessionHasErrors('rows');
});

test('invoice customer_id must reference an existing customer', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => (string) Str::uuid(),
        'company_id' => $company->id,
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('customer_id');
});

test('invoice company_id is required', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('company_id');
});

test('invoice company_id must reference an existing company', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'company_id' => (string) Str::uuid(),
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('company_id');
});

test('invoice number can be set explicitly and must be unique', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();
    Invoice::factory()->create(['number' => '2026-0050']);

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'number' => '2026-0050',
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'company_id' => $company->id,
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('number');
});

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
        'company_id' => $invoice->company_id,
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

test('invoice can be deleted and its rows are removed', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create();
    InvoiceRow::factory()->count(2)->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->delete(route('invoices.destroy', $invoice));

    $response->assertRedirect(route('invoices.index'));
    expect(Invoice::query()->find($invoice->id))->toBeNull();
    expect(InvoiceRow::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('invoice factory produces a valid language', function () {
    $invoice = Invoice::factory()->create();

    expect(['it', 'en', 'es'])->toContain($invoice->language);
});

test('invoice requires a language', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'company_id' => $company->id,
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
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'company_id' => $company->id,
        'language' => 'fr',
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('language');
});

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
    $response->assertSee($invoice->company->name);
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

test('guests are redirected to the login page when downloading an invoice pdf', function () {
    $invoice = Invoice::factory()->create();

    $this->get(route('invoices.pdf', $invoice))->assertRedirect(route('login'));
});

test('invoice preview renders the italian locale label', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create(['language' => 'it']);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->get(route('invoices.preview', $invoice));

    $response->assertOk();
    $response->assertSee('Fattura');
});

test('invoice pdf download sanitizes slashes in the invoice number', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create(['number' => '2026/0001']);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('2026-0001');
    expect($disposition)->not->toContain('2026/0001');
});

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
        ->where('duplicate.rows.0.price', 100)
        ->where('duplicate.rows.0.vat_rate', 22)
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

test('invoice create page ignores an array-valued duplicate param', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/invoices/create?duplicate[]=x');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate', null)
    );
});

test('a duplicated invoice can be saved as a new invoice', function () {
    $user = User::factory()->create();
    $source = Invoice::factory()->create(['language' => 'en', 'paid' => true]);
    InvoiceRow::factory()->create([
        'invoice_id' => $source->id, 'description' => 'Consulting', 'price' => 100, 'vat_rate' => 22,
    ]);

    $page = $this->actingAs($user)->get(route('invoices.create', ['duplicate' => $source->id]));
    $duplicate = $page->viewData('page')['props']['duplicate'];

    $this->actingAs($user)->post(route('invoices.store'), [
        'number' => Invoice::nextNumber(),
        'invoice_date' => now()->toDateString(),
        'paid' => false,
        'customer_id' => $duplicate['customer_id'],
        'company_id' => $duplicate['company_id'],
        'note' => $duplicate['note'] ?? '',
        'language' => $duplicate['language'],
        'rows' => $duplicate['rows'],
    ])->assertRedirect(route('invoices.index'))->assertSessionHasNoErrors();

    expect(Invoice::count())->toBe(2);
    $new = Invoice::query()->where('id', '!=', $source->id)->firstOrFail();
    expect($new->number)->not->toBe($source->number)
        ->and($new->paid)->toBeFalse()
        ->and($new->rows)->toHaveCount(1)
        ->and($new->company_id)->toBe($source->company_id);
    expect($source->fresh()->paid)->toBeTrue(); // source untouched
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

Replace the full contents of `tests/Feature/InvoiceTemplateTest.php` with:

```php
<?php

use App\Models\Invoice;
use App\Models\InvoiceRow;
use Illuminate\Support\Facades\App;

function renderInvoiceTemplate(Invoice $invoice): string
{
    App::setLocale($invoice->language);

    return view('invoices.template', [
        'invoice' => $invoice->load(['customer.country', 'company.country', 'rows']),
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
    expect($html)->toContain($invoice->company->name);
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

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=InvoiceTest`
Run: `php artisan test --compact --filter=InvoiceTemplateTest`
Expected: FAIL — `company_id` validation errors missing, `$invoice->company` undefined relation, etc.

- [ ] **Step 3: Add the `company_id` migration**

Create `database/migrations/2026_08_12_120100_add_company_id_to_invoices_table.php`:

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
            $table->foreignUuid('company_id')->after('id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
```

- [ ] **Step 4: Update the `Invoice` model**

Modify `app/Models/Invoice.php`:

Add `use Illuminate\Database\Eloquent\Relations\BelongsTo;` import if not present (it already is, reused for `customer()`). Change the `@property` block and `#[Fillable]` attribute:

```php
/**
 * @property string $id
 * @property string $number
 * @property Carbon $invoice_date
 * @property bool $paid
 * @property string $customer_id
 * @property string $company_id
 * @property string|null $note
 * @property string $language
 * @property-read float $subtotal
 * @property-read float $vat_total
 * @property-read float $total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['number', 'invoice_date', 'paid', 'customer_id', 'company_id', 'note', 'language'])]
```

Add this method next to `customer()`:

```php
    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
```

- [ ] **Step 5: Update `InvoiceFactory`**

Modify `database/factories/InvoiceFactory.php` — add `use App\Models\Company;` import and `'company_id' => Company::factory(),` to the `definition()` array (alongside `customer_id`):

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => null,
            'invoice_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'paid' => fake()->boolean(),
            'customer_id' => Customer::factory(),
            'company_id' => Company::factory(),
            'note' => fake()->optional()->sentence(),
            'language' => fake()->randomElement(['it', 'en', 'es']),
        ];
    }
}
```

- [ ] **Step 6: Require `company_id` in the form requests**

Modify `app/Http/Requests/StoreInvoiceRequest.php` — add this line right after `customer_id`:

```php
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
```

Modify `app/Http/Requests/UpdateInvoiceRequest.php` — add the same line right after `customer_id`.

- [ ] **Step 7: Update `InvoiceController`**

Replace the full contents of `app/Http/Controllers/InvoiceController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
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
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        $invoices = Invoice::query()
            ->with(['customer', 'rows'])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($query) => $query->where('name', 'like', "%{$search}%"));
            }))
            ->orderByDesc('number')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('invoices/Index', [
            'invoices' => $invoices,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(Request $request): Response
    {
        $duplicateId = is_string($id = $request->query('duplicate')) ? trim($id) : '';

        $source = $duplicateId !== ''
            ? Invoice::query()->with('rows')->find($duplicateId)
            : null;

        return Inertia::render('invoices/Create', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'defaultCompanyId' => Company::query()->where('is_default', true)->value('id'),
            'nextNumber' => Invoice::nextNumber(),
            'duplicate' => $source ? [
                'customer_id' => $source->customer_id,
                'company_id' => $source->company_id,
                'note' => $source->note,
                'language' => $source->language,
                'rows' => $source->rows->map(fn (InvoiceRow $row): array => [
                    'description' => $row->description,
                    'price' => $row->price,
                    'vat_rate' => $row->vat_rate,
                ])->all(),
            ] : null,
        ]);
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $invoice = Invoice::query()->create($request->safe()->except('rows'));

            $invoice->rows()->createMany($request->safe()->input('rows'));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice created.')]);

        return to_route('invoices.index');
    }

    public function edit(Invoice $invoice): Response
    {
        return Inertia::render('invoices/Edit', [
            'invoice' => $invoice->load('rows'),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        DB::transaction(function () use ($request, $invoice) {
            $invoice->update($request->safe()->except('rows'));

            $rows = collect($request->safe()->input('rows'));
            $keepIds = $rows->pluck('id')->filter()->all();

            $invoice->rows()->whereNotIn('id', $keepIds)->delete();

            foreach ($rows as $row) {
                $attributes = collect($row)->except('id')->all();

                if ($rowId = $row['id'] ?? null) {
                    $invoice->rows()->whereKey($rowId)->update($attributes);
                } else {
                    $invoice->rows()->create($attributes);
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice updated.')]);

        return to_route('invoices.index');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $invoice->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice deleted.')]);

        return to_route('invoices.index');
    }

    public function preview(Invoice $invoice): View
    {
        App::setLocale($invoice->language);

        return view('invoices.template', [
            'invoice' => $invoice->load(['customer.country', 'company.country', 'rows']),
        ]);
    }

    public function pdf(Invoice $invoice): HttpResponse
    {
        App::setLocale($invoice->language);

        return Pdf::loadView('invoices.template', [
            'invoice' => $invoice->load(['customer.country', 'company.country', 'rows']),
        ])->download(str_replace(['/', '\\'], '-', $invoice->number).'.pdf');
    }
}
```

- [ ] **Step 8: Rewrite the PDF/preview template**

Replace the full contents of `resources/views/invoices/template.blade.php` with:

```blade
@php
    $logoPath = $invoice->company->logo ? public_path($invoice->company->logo) : null;
    $logoData = $logoPath && file_exists($logoPath)
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
        .customer { padding: 0 16px; font-size: 11px; line-height: 1.5; }
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
                    <img class="logo" src="{{ $logoData }}" alt="{{ $invoice->company->name }}">
                @endif
                <div class="company">
                    <strong>{{ $invoice->company->name }}</strong><br>
                    {{ $invoice->company->address }}<br>
                    {{ $invoice->company->zip }} {{ $invoice->company->city }}, {{ $invoice->company->country?->name }}<br>
                    {{ __('invoice.tax_id') }}: {{ $invoice->company->tax_id }}<br>
                    {{ $invoice->company->email }} &mdash; {{ $invoice->company->phone }}
                </div>
            </td>
            <td class="customer">
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

- [ ] **Step 9: Delete the config files and clear the config cache**

```bash
rm config/company.php config/company.php.example
php artisan config:clear
```

- [ ] **Step 10: Refresh the local dev database**

```bash
php artisan migrate:fresh
```

This wipes local dev data (per project decision — not in production) so the new required `invoices.company_id` FK can be added cleanly.

- [ ] **Step 11: Run tests to verify they pass**

Run: `php artisan test --compact --filter=InvoiceTest`
Run: `php artisan test --compact --filter=InvoiceTemplateTest`
Expected: PASS (all tests)

- [ ] **Step 12: Format PHP**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 13: Commit**

```bash
git add database/migrations/2026_08_12_120100_add_company_id_to_invoices_table.php app/Models/Invoice.php database/factories/InvoiceFactory.php app/Http/Requests/StoreInvoiceRequest.php app/Http/Requests/UpdateInvoiceRequest.php app/Http/Controllers/InvoiceController.php resources/views/invoices/template.blade.php tests/Feature/InvoiceTest.php tests/Feature/InvoiceTemplateTest.php
git add config/company.php config/company.php.example
git commit -m "feat: require company_id on invoices, replace config/company.php in the PDF template"
```

Note: `git add` on the two deleted config files stages their removal.

---

### Task 3: Company CRUD backend (no logo yet)

**Files:**
- Create: `app/Http/Controllers/CompanyController.php`
- Create: `app/Http/Requests/StoreCompanyRequest.php`
- Create: `app/Http/Requests/UpdateCompanyRequest.php`
- Create: `routes/companies.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/CompanyTest.php` (append)

**Interfaces:**
- Consumes: `App\Models\Company` (Task 1).
- Produces: routes `companies.index`, `companies.create`, `companies.store`, `companies.edit`, `companies.update`, `companies.destroy`. Inertia components `companies/Index`, `companies/Create`, `companies/Edit` with props `companies`/`filters` (index), `countries` (create/edit), `company` (edit). Task 5 (frontend) and Task 7 (wayfinder types) consume these route names and prop shapes.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/CompanyTest.php` (after the existing 3 tests):

```php
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Str;

test('guests are redirected to the login page when visiting companies', function () {
    $this->get(route('companies.index'))->assertRedirect(route('login'));
});

test('companies index page can be rendered', function () {
    $user = User::factory()->create();
    Company::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('companies.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('companies/Index'));
});

test('company can be created with only a name', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Acme Corp',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('companies.index'));
    expect(Company::query()->where('name', 'Acme Corp')->exists())->toBeTrue();
});

test('company name is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => '',
    ]);

    $response->assertSessionHasErrors('name');
});

test('company email must be a valid address when present', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Acme Corp',
        'email' => 'not-an-email',
    ]);

    $response->assertSessionHasErrors('email');
});

test('company country_id must reference an existing country', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Acme Corp',
        'country_id' => (string) Str::uuid(),
    ]);

    $response->assertSessionHasErrors('country_id');
});

test('company can be updated', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->put(route('companies.update', $company), [
        'name' => 'New Name',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('companies.index'));
    expect($company->fresh()->name)->toBe('New Name');
});

test('the first company created becomes the default automatically', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'First Company',
    ]);

    $company = Company::query()->where('name', 'First Company')->firstOrFail();
    expect($company->is_default)->toBeTrue();
});

test('marking a company as default unsets the previous default', function () {
    $user = User::factory()->create();
    $first = Company::factory()->create(['is_default' => true]);

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Second Company',
        'is_default' => true,
    ]);

    $response->assertSessionHasNoErrors();
    expect($first->fresh()->is_default)->toBeFalse();
    expect(Company::query()->where('name', 'Second Company')->first()->is_default)->toBeTrue();
});

test('a company without invoices can be deleted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->delete(route('companies.destroy', $company));

    $response->assertRedirect(route('companies.index'));
    expect(Company::query()->find($company->id))->toBeNull();
});

test('a company with invoices cannot be deleted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Invoice::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->delete(route('companies.destroy', $company));

    $response->assertRedirect(route('companies.index'));
    expect(Company::query()->find($company->id))->not->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=CompanyTest`
Expected: FAIL — routes `companies.index` etc. do not exist.

- [ ] **Step 3: Create the form requests**

Create `app/Http/Requests/StoreCompanyRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country_id' => ['nullable', 'uuid', 'exists:countries,id'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'iban' => ['nullable', 'string', 'max:50'],
            'is_default' => ['boolean'],
        ];
    }
}
```

Create `app/Http/Requests/UpdateCompanyRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country_id' => ['nullable', 'uuid', 'exists:countries,id'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'iban' => ['nullable', 'string', 'max:50'],
            'is_default' => ['boolean'],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/CompanyController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Country;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        $companies = Company::query()
            ->with('country')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('companies/Index', [
            'companies' => $companies,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('companies/Create', [
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $isDefault = Company::query()->doesntExist() || $request->boolean('is_default');

            $company = Company::query()->create([
                ...$request->safe()->except(['is_default', 'logo', 'remove_logo']),
                'is_default' => $isDefault,
            ]);

            if ($isDefault) {
                Company::query()->whereKeyNot($company->id)->update(['is_default' => false]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company created.')]);

        return to_route('companies.index');
    }

    public function edit(Company $company): Response
    {
        return Inertia::render('companies/Edit', [
            'company' => $company,
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        DB::transaction(function () use ($request, $company) {
            $isDefault = $request->boolean('is_default');

            $company->update([
                ...$request->safe()->except(['is_default', 'logo', 'remove_logo']),
                'is_default' => $isDefault,
            ]);

            if ($isDefault) {
                Company::query()->whereKeyNot($company->id)->update(['is_default' => false]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company updated.')]);

        return to_route('companies.index');
    }

    public function destroy(Company $company): RedirectResponse
    {
        if ($company->invoices()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This company has invoices and cannot be deleted.')]);

            return to_route('companies.index');
        }

        $company->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company deleted.')]);

        return to_route('companies.index');
    }
}
```

Note: `except(['is_default', 'logo', 'remove_logo'])` already excludes the `logo`/`remove_logo` keys that Task 4 will add to the requests — safe to include now since `except()` silently ignores keys that aren't present.

- [ ] **Step 5: Register the routes**

Create `routes/companies.php`:

```php
<?php

use App\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('companies', CompanyController::class)->except('show');
});
```

Modify `routes/web.php` — add the require in alphabetical order:

```php
require __DIR__.'/settings.php';
require __DIR__.'/companies.php';
require __DIR__.'/countries.php';
require __DIR__.'/customers.php';
require __DIR__.'/invoices.php';
```

- [ ] **Step 6: Generate Wayfinder types**

```bash
php artisan wayfinder:generate
```

This creates `resources/js/routes/companies/index.ts` and `resources/js/actions/App/Http/Controllers/CompanyController.ts` (both gitignored, regenerated on demand — no need to commit them).

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --compact --filter=CompanyTest`
Expected: PASS (all tests)

- [ ] **Step 8: Format PHP**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/CompanyController.php app/Http/Requests/StoreCompanyRequest.php app/Http/Requests/UpdateCompanyRequest.php routes/companies.php routes/web.php tests/Feature/CompanyTest.php
git commit -m "feat: add Company CRUD controller, requests and routes"
```

---

### Task 4: Company logo upload

**Files:**
- Modify: `app/Http/Controllers/CompanyController.php`
- Modify: `app/Http/Requests/StoreCompanyRequest.php`
- Modify: `app/Http/Requests/UpdateCompanyRequest.php`
- Test: `tests/Feature/CompanyTest.php` (append)

**Interfaces:**
- Produces: `store`/`update` accept a `logo` file upload (validated image, max 2MB) and persist it to `public/images/companies/{company_id}.{ext}`, saving that relative path in `companies.logo`. `update` also accepts `remove_logo` (boolean) to clear the logo without uploading a new one. Frontend (Task 5) sends these as `logo: File | null` and `remove_logo: boolean` fields.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/CompanyTest.php`:

```php
use Illuminate\Http\UploadedFile;

test('company logo can be uploaded and is stored in public/images/companies', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Acme Corp',
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('companies.index'));

    $company = Company::query()->where('name', 'Acme Corp')->firstOrFail();
    expect($company->logo)->toBe("images/companies/{$company->id}.png");
    expect(file_exists(public_path($company->logo)))->toBeTrue();

    unlink(public_path($company->logo));
});

test('replacing a company logo deletes the previous file', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user)->put(route('companies.update', $company), [
        'name' => $company->name,
        'logo' => UploadedFile::fake()->image('first.jpg'),
    ])->assertSessionHasNoErrors();

    $firstPath = public_path($company->fresh()->logo);
    expect(file_exists($firstPath))->toBeTrue();

    $this->actingAs($user)->put(route('companies.update', $company), [
        'name' => $company->name,
        'logo' => UploadedFile::fake()->image('second.png'),
    ])->assertSessionHasNoErrors();

    $company->refresh();
    expect(file_exists($firstPath))->toBeFalse();
    expect(file_exists(public_path($company->logo)))->toBeTrue();
    expect($company->logo)->toBe("images/companies/{$company->id}.png");

    unlink(public_path($company->logo));
});

test('a company logo can be removed without uploading a new one', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user)->put(route('companies.update', $company), [
        'name' => $company->name,
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);
    $logoPath = public_path($company->fresh()->logo);
    expect(file_exists($logoPath))->toBeTrue();

    $response = $this->actingAs($user)->put(route('companies.update', $company), [
        'name' => $company->name,
        'remove_logo' => true,
    ]);

    $response->assertSessionHasNoErrors();
    expect($company->fresh()->logo)->toBeNull();
    expect(file_exists($logoPath))->toBeFalse();
});

test('company logo must be an image', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Acme Corp',
        'logo' => UploadedFile::fake()->create('not-an-image.pdf', 100),
    ]);

    $response->assertSessionHasErrors('logo');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=CompanyTest`
Expected: FAIL — `logo` is silently dropped (not yet validated/stored), so `$company->logo` stays `null`.

- [ ] **Step 3: Add validation rules**

Modify `app/Http/Requests/StoreCompanyRequest.php` — add after `iban`:

```php
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,svg,webp', 'max:2048'],
```

Modify `app/Http/Requests/UpdateCompanyRequest.php` — add after `iban`:

```php
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,svg,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
```

- [ ] **Step 4: Implement logo storage in the controller**

Modify `app/Http/Controllers/CompanyController.php` — change `use App\Http\Requests\...` imports stay the same; update `store()` and `update()` to call the new `syncLogo()` helper after the DB transaction, update `destroy()` to clean up the file, and add the two private helpers. Replace the full file with:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Country;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        $companies = Company::query()
            ->with('country')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('companies/Index', [
            'companies' => $companies,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('companies/Create', [
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $company = DB::transaction(function () use ($request) {
            $isDefault = Company::query()->doesntExist() || $request->boolean('is_default');

            $company = Company::query()->create([
                ...$request->safe()->except(['is_default', 'logo', 'remove_logo']),
                'is_default' => $isDefault,
            ]);

            if ($isDefault) {
                Company::query()->whereKeyNot($company->id)->update(['is_default' => false]);
            }

            return $company;
        });

        $this->syncLogo($company, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company created.')]);

        return to_route('companies.index');
    }

    public function edit(Company $company): Response
    {
        return Inertia::render('companies/Edit', [
            'company' => $company,
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        DB::transaction(function () use ($request, $company) {
            $isDefault = $request->boolean('is_default');

            $company->update([
                ...$request->safe()->except(['is_default', 'logo', 'remove_logo']),
                'is_default' => $isDefault,
            ]);

            if ($isDefault) {
                Company::query()->whereKeyNot($company->id)->update(['is_default' => false]);
            }
        });

        $this->syncLogo($company, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company updated.')]);

        return to_route('companies.index');
    }

    public function destroy(Company $company): RedirectResponse
    {
        if ($company->invoices()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This company has invoices and cannot be deleted.')]);

            return to_route('companies.index');
        }

        if ($company->logo) {
            $this->deleteLogoFile($company->logo);
        }

        $company->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company deleted.')]);

        return to_route('companies.index');
    }

    private function syncLogo(Company $company, StoreCompanyRequest|UpdateCompanyRequest $request): void
    {
        if ($request->hasFile('logo')) {
            if ($company->logo) {
                $this->deleteLogoFile($company->logo);
            }

            $file = $request->file('logo');
            $directory = public_path('images/companies');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $filename = $company->id.'.'.$file->extension();
            $file->move($directory, $filename);

            $company->update(['logo' => 'images/companies/'.$filename]);

            return;
        }

        if ($request->boolean('remove_logo') && $company->logo) {
            $this->deleteLogoFile($company->logo);
            $company->update(['logo' => null]);
        }
    }

    private function deleteLogoFile(string $path): void
    {
        $fullPath = public_path($path);

        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=CompanyTest`
Expected: PASS (all tests)

- [ ] **Step 6: Format PHP**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/CompanyController.php app/Http/Requests/StoreCompanyRequest.php app/Http/Requests/UpdateCompanyRequest.php tests/Feature/CompanyTest.php
git commit -m "feat: support company logo upload, replacement and removal"
```

---

### Task 5: Company frontend pages

**Files:**
- Create: `resources/js/pages/companies/Index.vue`
- Create: `resources/js/pages/companies/Create.vue`
- Create: `resources/js/pages/companies/Edit.vue`
- Modify: `resources/js/components/AppSidebar.vue`

**Interfaces:**
- Consumes: `companies.index`/`create`/`store`/`edit`/`update`/`destroy` routes and `CompanyController` actions (Task 3/4, generated by Wayfinder into `@/routes/companies` and `@/actions/App/Http/Controllers/CompanyController`), props `companies`/`filters` (Index), `countries` (Create/Edit), `company` (Edit) as returned by `CompanyController`.
- No new interfaces produced for later tasks beyond the sidebar nav entry (visual only).

- [ ] **Step 1: Ensure Wayfinder types are current**

```bash
php artisan wayfinder:generate
```

- [ ] **Step 2: Create the Index page**

Create `resources/js/pages/companies/Index.vue`:

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, edit, index } from '@/routes/companies';
import type { BreadcrumbItem } from '@/types';

type Company = {
    id: string;
    name: string;
    city: string | null;
    email: string | null;
    is_default: boolean;
    country: { id: string; name: string } | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const props = defineProps<{
    companies: {
        data: Company[];
        links: PaginationLink[];
    };
    filters: {
        search: string;
    };
}>();

const search = ref(props.filters.search);

function onSearch(): void {
    router.get(
        index().url,
        { search: search.value },
        { preserveState: true, replace: true },
    );
}

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'Companies', href: index() },
        ] satisfies BreadcrumbItem[],
    }),
});
</script>

<template>
    <Head title="Companies" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                title="Companies"
                description="Manage the companies that can issue invoices"
            />
            <Button as-child>
                <Link :href="create()">
                    <Plus />
                    New company
                </Link>
            </Button>
        </div>

        <form class="max-w-sm" @submit.prevent="onSearch">
            <Input v-model="search" placeholder="Search by name..." />
        </form>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">City</th>
                        <th class="px-4 py-2 font-medium">Country</th>
                        <th class="px-4 py-2 font-medium">Email</th>
                        <th class="px-4 py-2 font-medium">Default</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="company in companies.data"
                        :key="company.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">{{ company.name }}</td>
                        <td class="px-4 py-2">{{ company.city ?? '—' }}</td>
                        <td class="px-4 py-2">{{ company.country?.name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ company.email ?? '—' }}</td>
                        <td class="px-4 py-2">{{ company.is_default ? 'Yes' : '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            <Link
                                :href="edit(company.id)"
                                class="text-primary underline-offset-4 hover:underline"
                            >
                                Edit
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="companies.data.length === 0">
                        <td colspan="6" class="px-4 py-6 text-center text-muted-foreground">
                            No companies found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="companies.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in companies.links"
                :key="i"
                :href="link.url ?? ''"
                :class="[
                    'rounded-md px-3 py-1 text-sm',
                    link.active
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-accent',
                    !link.url && 'pointer-events-none opacity-50',
                ]"
            >
                <span v-html="link.label" />
            </Link>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Create the Create page**

Create `resources/js/pages/companies/Create.vue`:

```vue
<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import CompanyController from '@/actions/App/Http/Controllers/CompanyController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/companies';
import type { BreadcrumbItem } from '@/types';

type Country = {
    id: string;
    name: string;
};

defineProps<{
    countries: Country[];
}>();

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'Companies', href: index() },
        ] satisfies BreadcrumbItem[],
    }),
});

const form = useForm({
    name: '',
    tax_id: '',
    address: '',
    zip: '',
    city: '',
    country_id: '',
    email: '',
    phone: '',
    iban: '',
    is_default: false,
    logo: null as File | null,
});

function onLogoChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    form.logo = target.files?.[0] ?? null;
}

function submit(): void {
    form.post(CompanyController.store().url);
}
</script>

<template>
    <Head title="New company" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            title="New company"
            description="Add an issuing company to the registry"
        />

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" required autofocus placeholder="Company name" />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="tax_id">Tax ID</Label>
                <Input id="tax_id" v-model="form.tax_id" placeholder="Tax identification number" />
                <InputError :message="form.errors.tax_id" />
            </div>

            <div class="grid gap-2">
                <Label for="address">Address</Label>
                <Input id="address" v-model="form.address" placeholder="Address" />
                <InputError :message="form.errors.address" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="zip">ZIP</Label>
                    <Input id="zip" v-model="form.zip" placeholder="ZIP code" />
                    <InputError :message="form.errors.zip" />
                </div>
                <div class="grid gap-2">
                    <Label for="city">City</Label>
                    <Input id="city" v-model="form.city" placeholder="City" />
                    <InputError :message="form.errors.city" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="country_id">Country</Label>
                <Select v-model="form.country_id">
                    <SelectTrigger id="country_id" class="w-full">
                        <SelectValue placeholder="Select a country" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="country in countries"
                            :key="country.id"
                            :value="country.id"
                        >
                            {{ country.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.country_id" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input id="email" type="email" v-model="form.email" placeholder="Email address" />
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input id="phone" v-model="form.phone" placeholder="Phone number" />
                    <InputError :message="form.errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="iban">IBAN</Label>
                    <Input id="iban" v-model="form.iban" placeholder="Bank account IBAN" />
                    <InputError :message="form.errors.iban" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="logo">Logo</Label>
                <input
                    id="logo"
                    type="file"
                    accept="image/png,image/jpeg,image/svg+xml,image/webp"
                    class="border-input dark:bg-input/30 w-full rounded-md border bg-transparent px-3 py-1.5 text-sm shadow-xs file:mr-3 file:rounded-sm file:border-0 file:bg-transparent file:text-sm file:font-medium"
                    @change="onLogoChange"
                >
                <InputError :message="form.errors.logo" />
            </div>

            <div class="flex items-center gap-2">
                <Checkbox id="is_default" v-model="form.is_default" />
                <Label for="is_default">Default company for new invoices</Label>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button :disabled="form.processing" type="submit">Save</Button>
                <Link :href="index()" class="text-sm text-muted-foreground hover:underline">
                    Cancel
                </Link>
            </div>
        </form>
    </div>
</template>
```

- [ ] **Step 4: Create the Edit page**

Create `resources/js/pages/companies/Edit.vue`:

```vue
<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import CompanyController from '@/actions/App/Http/Controllers/CompanyController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/companies';
import type { BreadcrumbItem } from '@/types';

type Country = {
    id: string;
    name: string;
};

type Company = {
    id: string;
    name: string;
    tax_id: string | null;
    address: string | null;
    zip: string | null;
    city: string | null;
    country_id: string | null;
    email: string | null;
    phone: string | null;
    iban: string | null;
    logo: string | null;
    is_default: boolean;
};

const props = defineProps<{
    company: Company;
    countries: Country[];
}>();

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'Companies', href: index() },
        ] satisfies BreadcrumbItem[],
    }),
});

const form = useForm({
    name: props.company.name,
    tax_id: props.company.tax_id ?? '',
    address: props.company.address ?? '',
    zip: props.company.zip ?? '',
    city: props.company.city ?? '',
    country_id: props.company.country_id ?? '',
    email: props.company.email ?? '',
    phone: props.company.phone ?? '',
    iban: props.company.iban ?? '',
    is_default: props.company.is_default,
    logo: null as File | null,
    remove_logo: false,
});

function onLogoChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    form.logo = target.files?.[0] ?? null;
    if (form.logo) {
        form.remove_logo = false;
    }
}

function submit(): void {
    form.put(CompanyController.update(props.company.id).url);
}

function onDelete(): void {
    if (confirm('Delete this company? This cannot be undone.')) {
        router.delete(CompanyController.destroy(props.company.id).url);
    }
}
</script>

<template>
    <Head title="Edit company" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading title="Edit company" :description="`Update ${company.name}`" />

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" required autofocus placeholder="Company name" />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="tax_id">Tax ID</Label>
                <Input id="tax_id" v-model="form.tax_id" placeholder="Tax identification number" />
                <InputError :message="form.errors.tax_id" />
            </div>

            <div class="grid gap-2">
                <Label for="address">Address</Label>
                <Input id="address" v-model="form.address" placeholder="Address" />
                <InputError :message="form.errors.address" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="zip">ZIP</Label>
                    <Input id="zip" v-model="form.zip" placeholder="ZIP code" />
                    <InputError :message="form.errors.zip" />
                </div>
                <div class="grid gap-2">
                    <Label for="city">City</Label>
                    <Input id="city" v-model="form.city" placeholder="City" />
                    <InputError :message="form.errors.city" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="country_id">Country</Label>
                <Select v-model="form.country_id">
                    <SelectTrigger id="country_id" class="w-full">
                        <SelectValue placeholder="Select a country" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="country in countries"
                            :key="country.id"
                            :value="country.id"
                        >
                            {{ country.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.country_id" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input id="email" type="email" v-model="form.email" placeholder="Email address" />
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input id="phone" v-model="form.phone" placeholder="Phone number" />
                    <InputError :message="form.errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="iban">IBAN</Label>
                    <Input id="iban" v-model="form.iban" placeholder="Bank account IBAN" />
                    <InputError :message="form.errors.iban" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="logo">Logo</Label>
                <img
                    v-if="company.logo && !form.remove_logo"
                    :src="`/${company.logo}`"
                    alt="Current logo"
                    class="h-16 w-auto rounded border object-contain p-1"
                >
                <input
                    id="logo"
                    type="file"
                    accept="image/png,image/jpeg,image/svg+xml,image/webp"
                    class="border-input dark:bg-input/30 w-full rounded-md border bg-transparent px-3 py-1.5 text-sm shadow-xs file:mr-3 file:rounded-sm file:border-0 file:bg-transparent file:text-sm file:font-medium"
                    @change="onLogoChange"
                >
                <InputError :message="form.errors.logo" />
                <div v-if="company.logo" class="flex items-center gap-2">
                    <Checkbox id="remove_logo" v-model="form.remove_logo" />
                    <Label for="remove_logo">Remove current logo</Label>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <Checkbox id="is_default" v-model="form.is_default" />
                <Label for="is_default">Default company for new invoices</Label>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button :disabled="form.processing" type="submit">Save</Button>
                <Link :href="index()" class="text-sm text-muted-foreground hover:underline">
                    Cancel
                </Link>
            </div>
        </form>

        <div class="border-t pt-6">
            <Button variant="destructive" type="button" @click="onDelete">
                Delete company
            </Button>
        </div>
    </div>
</template>
```

- [ ] **Step 5: Add the sidebar nav entry**

Replace the `<script setup>` block of `resources/js/components/AppSidebar.vue` with:

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BookOpen, Building2, FolderGit2, Globe, LayoutGrid, Receipt, Users } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as companiesIndex } from '@/routes/companies';
import { index as countriesIndex } from '@/routes/countries';
import { index as customersIndex } from '@/routes/customers';
import { index as invoicesIndex } from '@/routes/invoices';
import type { NavItem } from '@/types';

const dashboardUrl = computed(() => dashboard().url);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
    {
        title: 'Customers',
        href: customersIndex().url,
        icon: Users,
    },
    {
        title: 'Companies',
        href: companiesIndex().url,
        icon: Building2,
    },
    {
        title: 'Invoices',
        href: invoicesIndex().url,
        icon: Receipt,
    },
    {
        title: 'Countries',
        href: countriesIndex().url,
        icon: Globe,
    },
]);

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/simonecosci/biglins',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://github.com/simonecosci/biglins/wiki',
        icon: BookOpen,
    },
];
</script>
```

(The `<template>` block is unchanged.)

- [ ] **Step 6: Verify backend tests referencing these components still pass**

Run: `php artisan test --compact --filter=CompanyTest`
Expected: PASS (the `companies index page can be rendered` test only checks the component name, unaffected by the new files, but re-run as a sanity check).

- [ ] **Step 7: Type-check and lint the frontend**

```bash
npm run types:check
npm run lint
```

Fix any reported issues before proceeding.

- [ ] **Step 8: Commit**

```bash
git add resources/js/pages/companies resources/js/components/AppSidebar.vue
git commit -m "feat: add Company registry pages and sidebar entry"
```

---

### Task 6: Invoice frontend — pick the issuing company

**Files:**
- Modify: `resources/js/pages/invoices/Create.vue`
- Modify: `resources/js/pages/invoices/Edit.vue`

**Interfaces:**
- Consumes: `companies: {id, name}[]` and `defaultCompanyId: string | null` props from `InvoiceController::create()` (Task 2), `companies` prop from `InvoiceController::edit()`, `invoice.company_id` and `duplicate.company_id` (Task 2).

- [ ] **Step 1: Add the company select to Create.vue**

Modify `resources/js/pages/invoices/Create.vue`:

Add a `Company` type and extend the props (right after the `Customer` type):

```ts
type Company = {
    id: string;
    name: string;
};
```

Update the `props` declaration:

```ts
const props = defineProps<{
    customers: Customer[];
    companies: Company[];
    defaultCompanyId: string | null;
    nextNumber: string;
    duplicate: {
        customer_id: string;
        company_id: string;
        note: string | null;
        language: string;
        rows: InvoiceRowForm[];
    } | null;
}>();
```

Update the `useForm` call to include `company_id`:

```ts
const form = useForm({
    number: props.nextNumber,
    invoice_date: new Date().toISOString().slice(0, 10),
    paid: false,
    customer_id: props.duplicate?.customer_id ?? '',
    company_id: props.duplicate?.company_id ?? props.defaultCompanyId ?? '',
    note: props.duplicate?.note ?? '',
    language: props.duplicate?.language ?? 'es',
    rows: props.duplicate?.rows ?? [{ description: '', price: 0, vat_rate: 0 }],
});
```

Add a `company_id` `Select` in the template, right after the `customer_id` select block:

```vue
            <div class="grid gap-2">
                <Label for="company_id">Issuing company</Label>
                <Select v-model="form.company_id">
                    <SelectTrigger id="company_id" class="w-full">
                        <SelectValue placeholder="Select a company" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="company in companies"
                            :key="company.id"
                            :value="company.id"
                        >
                            {{ company.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.company_id" />
            </div>
```

- [ ] **Step 2: Add the company select to Edit.vue**

Modify `resources/js/pages/invoices/Edit.vue`:

Add the `Company` type (after the `Customer` type):

```ts
type Company = {
    id: string;
    name: string;
};
```

Update the `Invoice` type to include `company_id`:

```ts
type Invoice = {
    id: string;
    number: string;
    invoice_date: string;
    paid: boolean;
    customer_id: string;
    company_id: string;
    note: string | null;
    language: string;
    rows: InvoiceRow[];
};
```

Update the `props` declaration:

```ts
const props = defineProps<{
    invoice: Invoice;
    customers: Customer[];
    companies: Company[];
}>();
```

Update the `useForm` call:

```ts
const form = useForm({
    number: props.invoice.number,
    invoice_date: props.invoice.invoice_date,
    paid: props.invoice.paid,
    customer_id: props.invoice.customer_id,
    company_id: props.invoice.company_id,
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

Add the same `company_id` `Select` block used in Create.vue, right after the `customer_id` select block.

- [ ] **Step 3: Update the backend test that asserts the Create page props**

This was already covered in Task 2 (`invoice create page can be rendered` asserts `->has('companies')`, and the duplicate-prefill tests assert `duplicate.company_id`) — no further backend test changes needed here.

- [ ] **Step 4: Type-check and lint**

```bash
npm run types:check
npm run lint
```

- [ ] **Step 5: Run the invoice backend tests once more as a regression check**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/invoices/Create.vue resources/js/pages/invoices/Edit.vue
git commit -m "feat: let invoices pick their issuing company"
```

---

### Task 7: Full verification sweep

**Files:** none (verification only).

- [ ] **Step 1: Run the full Pest suite**

```bash
php artisan test --compact
```

Expected: PASS, all tests green.

- [ ] **Step 2: Format and statically analyze PHP**

```bash
vendor/bin/pint --dirty --format agent
composer types:check
```

Fix anything reported.

- [ ] **Step 3: Lint, format-check and type-check the frontend**

```bash
npm run lint:check
npm run format:check
npm run types:check
```

Fix anything reported (running `npm run lint` / `npm run format` auto-fixes most issues).

- [ ] **Step 4: Build the frontend**

```bash
npm run build
```

Expected: build succeeds with no errors (confirms the new Vue pages compile and Wayfinder imports resolve).

- [ ] **Step 5: Confirm no leftover references to the old config file**

```bash
grep -rn "config('company" app resources routes tests --include="*.php" --include="*.blade.php"
```

Expected: no output. Also confirm the files are gone:

```bash
test -f config/company.php && echo "STILL EXISTS" || echo "removed"
test -f config/company.php.example && echo "STILL EXISTS" || echo "removed"
```

Expected: both print `removed`.

- [ ] **Step 6: Manual smoke test**

Start the dev server (`composer run dev` or `php artisan serve --port=8080` + `npm run dev`), then in a browser:
1. Visit `/companies`, create a company with a logo, mark it default.
2. Visit `/invoices/create`, confirm the company select is pre-filled with the default company and the customer/rows flow still works.
3. Save the invoice, open its PDF preview, confirm the new company's name/logo/address appear in place of the old static config data.
4. Go back to `/companies`, try deleting the company used by that invoice — confirm it's blocked with an error message.

- [ ] **Step 7: Commit any fixes found during verification**

If Step 1–5 required fixes, commit them:

```bash
git add -A
git commit -m "chore: fix issues found during full verification sweep"
```

(Skip this step if no fixes were needed.)

---

### Task 8: Update the GitHub wiki

**Files (in the separate `biglins.wiki` git repository, not this checkout):**
- Create: `Anagrafica-Aziende.md`
- Modify: `Home.md`
- Modify: `_Sidebar.md`
- Modify: `Guida-Rapida.md`
- Modify: `Fatture.md`
- Modify: `Architettura.md`
- Modify: `Sviluppo-e-Test.md`

- [ ] **Step 1: Clone the wiki repo to scratch space**

```bash
git clone https://github.com/simonecosci/biglins.wiki.git /tmp/biglins-wiki
cd /tmp/biglins-wiki
```

(Use this project's actual scratchpad directory instead of `/tmp` if the executing environment provides one.)

- [ ] **Step 2: Create the new `Anagrafica-Aziende.md` page**

Create `Anagrafica-Aziende.md` with:

```markdown
# Anagrafica aziende

Gestione delle aziende/entità che possono emettere fatture. Sostituisce il vecchio file di configurazione `config/company.php`: ogni fattura ora è collegata a una company tramite `company_id`, scelta in creazione o modifica.

Percorso: **Companies** nel menu principale (`/companies`).

CRUD completo (elenco, creazione, modifica, eliminazione) gestito da `CompanyController`.

Campi della company:

| Campo | Obbligatorio | Note |
|---|---|---|
| `name` | Sì | Ragione sociale |
| `tax_id` | No | Partita IVA / codice fiscale |
| `address`, `zip`, `city` | No | Indirizzo |
| `country` | No | Selezionato dall'elenco [Paesi](Anagrafica-Clienti#paesi) |
| `email`, `phone` | No | Contatti |
| `iban` | No | Coordinate bancarie |
| `logo` | No | Immagine caricata via upload (vedi sotto) |
| `is_default` | — | Gestito dall'app, non modificabile a mano nel form come campo libero |

## Logo

Il logo si carica come upload nel form (non più un percorso file da configurare a mano). Formati accettati: JPEG, PNG, SVG, WEBP, max 2MB. Viene salvato in `public/images/companies/{company_id}.{estensione}` e referenziato nel PDF fattura tramite lo stesso meccanismo di prima (embed base64 nel template DomPDF). Sostituire il logo elimina il file precedente; è possibile rimuoverlo senza caricarne uno nuovo.

## Company predefinita

Una sola company può essere marcata come **predefinita** (`is_default`): impostarne una nuova azzera automaticamente il flag sulle altre. La prima company creata nel sistema diventa predefinita in automatico. In fase di creazione di una nuova fattura, la company predefinita viene preselezionata nel form.

## Eliminazione

Una company con fatture associate **non può essere eliminata**: l'operazione viene rifiutata con un messaggio d'errore esplicito (oltre al vincolo `restrictOnDelete` a livello database).

## Collegamento con le fatture

Ogni fattura ha un campo `company_id` obbligatorio, selezionabile tramite menu a tendina nel form di creazione/modifica fattura. Il PDF/anteprima fattura (vedi [Fatture](Fatture)) usa i dati della company collegata al posto del vecchio `config('company.*')`.
```

- [ ] **Step 3: Update `Home.md`**

In `Home.md`, change the intro sentence (line 3) from:

```markdown
**Biglins** è un'applicazione web di fatturazione pensata per liberi professionisti: anagrafica clienti, emissione fatture con righe e IVA, numerazione progressiva automatica, anteprima e generazione PDF, duplicazione fattura.
```

to:

```markdown
**Biglins** è un'applicazione web di fatturazione pensata per liberi professionisti: anagrafica clienti, anagrafica aziende emittenti (multi-azienda, con logo), emissione fatture con righe e IVA, numerazione progressiva automatica, anteprima e generazione PDF, duplicazione fattura.
```

And add a row to the guides table (after the `Anagrafica clienti` row):

```markdown
| [Anagrafica aziende](Anagrafica-Aziende) | Gestione aziende emittenti, logo, azienda predefinita |
```

- [ ] **Step 4: Update `_Sidebar.md`**

Add a line after `* [Anagrafica clienti](Anagrafica-Clienti)`:

```markdown
* [Anagrafica aziende](Anagrafica-Aziende)
```

- [ ] **Step 5: Update `Guida-Rapida.md`**

Replace the entire `## Dati dell'emittente per il PDF` section (currently describing `config/company.php`) with:

```markdown
## Dati dell'emittente per il PDF

I dati dell'entità che emette le fatture (nome, partita IVA, indirizzo, IBAN, logo) si gestiscono dall'app stessa, in **Companies** (`/companies`) — non più tramite un file di configurazione. Al primo avvio, crea almeno una company dalla UI; la prima creata diventa automaticamente quella predefinita, usata come preselezione quando si crea una nuova fattura. Vedi [Anagrafica aziende](Anagrafica-Aziende) per i dettagli.
```

- [ ] **Step 6: Update `Fatture.md`**

Replace line 36:

```markdown
Il PDF include i dati dell'emittente configurati in `config/company.php` (vedi [Guida rapida](Guida-Rapida#dati-dellemittente-per-il-pdf)) oltre ai dati del cliente e delle righe fattura.
```

with:

```markdown
Il PDF include i dati della [company](Anagrafica-Aziende) collegata alla fattura (`company_id`) oltre ai dati del cliente e delle righe fattura.
```

- [ ] **Step 7: Update `Architettura.md`**

Replace line 20:

```markdown
- `config/company.php` — dati dell'emittente usati nel PDF fattura (file locale, **non versionato**)
```

with:

```markdown
- `app/Models/Company.php` — anagrafica delle aziende emittenti (nome, dati fiscali, logo, azienda predefinita), collegata a `Invoice` tramite `company_id`
```

Update the controllers/models bullets (lines 16-17) to mention `Company`/`CompanyController`:

```markdown
- `app/Http/Controllers` — controller Inertia (`CustomerController`, `CompanyController`, `InvoiceController`, `CountryController`, controller di autenticazione/impostazioni)
- `app/Models` — `Customer`, `Company`, `Invoice`, `InvoiceRow`, `Country`, `User` (chiavi primarie **UUID**, tranne `User` che usa un id auto-incrementante)
```

Update the relations diagram (lines 27-33):

```markdown
Country (1) ──< (N) Customer
Country (1) ──< (N) Company
Customer (1) ──< (N) Invoice  [restrictOnDelete: no cancellazione cliente con fatture]
Company  (1) ──< (N) Invoice  [restrictOnDelete: no cancellazione company con fatture]
Invoice  (1) ──< (N) InvoiceRow  [cascadeOnDelete: righe eliminate con la fattura]
```

Update the routing table (lines 42-48) to add a `companies.php` row:

```markdown
| File | Contenuto |
|---|---|
| `web.php` | Home pubblica, dashboard |
| `settings.php` | Profilo, sicurezza, aspetto |
| `companies.php` | CRUD aziende emittenti |
| `countries.php` | CRUD paesi |
| `customers.php` | CRUD clienti |
| `invoices.php` | CRUD fatture + anteprima/PDF |
```

- [ ] **Step 8: Update `Sviluppo-e-Test.md`**

Replace line 60:

```markdown
- Modificare `config/company.php` senza motivo: contiene i dati reali dell'entità di fatturazione ed è intenzionalmente escluso da git.
```

with:

```markdown
- Caricare un logo aziendale reale in ambienti condivisi/demo: i file finiscono in `public/images/companies/`, non versionato di proposito per gli asset caricati dagli utenti.
```

- [ ] **Step 9: Commit and push**

```bash
git add -A
git commit -m "docs: document the company registry, replace config/company.php references"
git push origin master
```

- [ ] **Step 10: Clean up the scratch clone**

```bash
cd -
rm -rf /tmp/biglins-wiki
```
