# Scadenziario (Invoice Row Expiration Tracking) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let invoice rows optionally track a service expiration date, and surface active subscriptions on the Dashboard as invoice-grouped cards with one-click renewal (creates a new invoice, +1 year per row) and cancellation.

**Architecture:** Two new nullable/defaulted columns on the existing `invoice_rows` table (no new tables). All new business logic lives on `InvoiceRow` (a query scope + a computed, non-persisted urgency accessor) and a new `SubscriptionController` with three actions (renew/cancelRow/cancelGroup), scoped to the current company through the `invoice` relation since `invoice_rows` has no `company_id` of its own. The Dashboard controller aggregates and groups the data server-side; a new Vue widget renders it.

**Tech Stack:** Laravel 13 (PHP 8.5), Inertia v3 + Vue 3, Pest 5, Laravel Wayfinder (typed route/action generation), shadcn/ui (Card, Badge, Button) + Tailwind v4.

**Spec:** [docs/superpowers/specs/2026-08-13-scadenziario-design.md](../specs/2026-08-13-scadenziario-design.md)

## Global Constraints

- UUID primary keys throughout (`HasUuids`); do not introduce auto-increment ids.
- No new tables — extend `invoice_rows` only. Do not edit the already-applied `invoice_rows` migrations in place; add a new migration.
- `InvoiceRow` has no `company_id` column — every company-scoping check goes through `invoice.company_id`, matching the existing `ScopesToCurrentCompany` trait pattern used by `InvoiceController`.
- Models declare fillable fields via the `#[Fillable([...])]` attribute (not `protected $fillable`), matching every existing model.
- Backed string enums, PascalCase cases, lower-case string values — matches `App\Enums\ProductType`.
- Always use curly braces for control structures; explicit return types and param type hints on every method.
- Tests are Pest feature tests (`test(...) { ... }`, `expect(...)`) in `tests/Feature`; this codebase has no dedicated Unit test layer for model behavior — see `tests/Feature/InvoiceTest.php` for the established style (`actingAs`, `withSession(['current_company_id' => $company->id])`, `Carbon::setTestNow()`).
- After any PHP change, run `vendor/bin/pint --dirty --format agent`.
- After any route change, run `php artisan wayfinder:generate --no-interaction` so the generated `@/actions/...` and `@/routes/...` TypeScript files stay in sync (the Vite plugin also regenerates them on `npm run dev`/`build`).
- Frontend labels go in all three locale files: `resources/js/lang/en.ts`, `it.ts`, `es.ts` — never hardcode UI strings.

---

### Task 1: `InvoiceRow` subscription fields, enums, scope, and urgency accessor

**Files:**
- Create: `database/migrations/2026_08_13_150000_add_expiration_date_and_subscription_status_to_invoice_rows_table.php`
- Create: `app/Enums/SubscriptionStatus.php`
- Create: `app/Enums/ExpirationUrgency.php`
- Modify: `app/Models/InvoiceRow.php`
- Modify: `database/factories/InvoiceRowFactory.php`
- Test: `tests/Feature/InvoiceRowTest.php` (new file)

**Interfaces:**
- Produces: `InvoiceRow::scopeSubscriptions(Builder $query): Builder` (usable as `InvoiceRow::query()->subscriptions()` or `$invoice->rows()->subscriptions()`); `InvoiceRow->expiration_urgency: ?ExpirationUrgency` (magic accessor backing method `expirationUrgency()`); `App\Enums\SubscriptionStatus::{Active,Cancelled}`; `App\Enums\ExpirationUrgency::{Expired,ExpiringSoon,Upcoming}`; `InvoiceRowFactory::subscription()` state.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\ExpirationUrgency;
use App\Enums\SubscriptionStatus;
use App\Models\InvoiceRow;
use Illuminate\Support\Carbon;

test('scopeSubscriptions only returns rows with an expiration date and active status', function () {
    $active = InvoiceRow::factory()->create([
        'expiration_date' => '2026-09-01',
        'subscription_status' => SubscriptionStatus::Active,
    ]);
    InvoiceRow::factory()->create(['expiration_date' => null]);
    InvoiceRow::factory()->create([
        'expiration_date' => '2026-09-01',
        'subscription_status' => SubscriptionStatus::Cancelled,
    ]);

    $result = InvoiceRow::query()->subscriptions()->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($active->id);
});

test('expiration_urgency is null when there is no expiration date', function () {
    $row = InvoiceRow::factory()->create(['expiration_date' => null]);

    expect($row->expiration_urgency)->toBeNull();
});

test('expiration_urgency is expired for a past date', function () {
    Carbon::setTestNow('2026-08-13');
    $row = InvoiceRow::factory()->create(['expiration_date' => '2026-08-01']);

    expect($row->expiration_urgency)->toBe(ExpirationUrgency::Expired);

    Carbon::setTestNow();
});

test('expiration_urgency is expiring_soon within the next 30 days, boundaries included', function () {
    Carbon::setTestNow('2026-08-13');

    $today = InvoiceRow::factory()->create(['expiration_date' => '2026-08-13']);
    $boundary = InvoiceRow::factory()->create(['expiration_date' => '2026-09-12']);

    expect($today->expiration_urgency)->toBe(ExpirationUrgency::ExpiringSoon);
    expect($boundary->expiration_urgency)->toBe(ExpirationUrgency::ExpiringSoon);

    Carbon::setTestNow();
});

test('expiration_urgency is upcoming beyond 30 days', function () {
    Carbon::setTestNow('2026-08-13');
    $row = InvoiceRow::factory()->create(['expiration_date' => '2026-09-13']);

    expect($row->expiration_urgency)->toBe(ExpirationUrgency::Upcoming);

    Carbon::setTestNow();
});

test('subscription factory state produces a row with an expiration date', function () {
    $row = InvoiceRow::factory()->subscription()->create();

    expect($row->expiration_date)->not->toBeNull();
    expect($row->subscription_status)->toBe(SubscriptionStatus::Active);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/InvoiceRowTest.php`
Expected: FAIL — `expiration_date`/`subscription_status` columns and `scopeSubscriptions`/`expiration_urgency` don't exist yet.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_rows', function (Blueprint $table) {
            $table->date('expiration_date')->nullable()->after('vat_rate');
            $table->string('subscription_status')->default('active')->after('expiration_date');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_rows', function (Blueprint $table) {
            $table->dropColumn(['expiration_date', 'subscription_status']);
        });
    }
};
```

- [ ] **Step 4: Create the enums**

`app/Enums/SubscriptionStatus.php`:

```php
<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
}
```

`app/Enums/ExpirationUrgency.php`:

```php
<?php

namespace App\Enums;

enum ExpirationUrgency: string
{
    case Expired = 'expired';
    case ExpiringSoon = 'expiring_soon';
    case Upcoming = 'upcoming';
}
```

- [ ] **Step 5: Update the `InvoiceRow` model**

Replace the full contents of `app/Models/InvoiceRow.php`:

```php
<?php

namespace App\Models;

use App\Enums\ExpirationUrgency;
use App\Enums\SubscriptionStatus;
use Database\Factories\InvoiceRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $invoice_id
 * @property string $description
 * @property float $quantity
 * @property float $price
 * @property float $vat_rate
 * @property Carbon|null $expiration_date
 * @property SubscriptionStatus $subscription_status
 * @property-read float $total
 * @property-read ExpirationUrgency|null $expiration_urgency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['invoice_id', 'description', 'quantity', 'price', 'vat_rate', 'expiration_date', 'subscription_status'])]
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
            'expiration_date' => 'date',
            'subscription_status' => SubscriptionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopeSubscriptions(Builder $query): Builder
    {
        return $query
            ->whereNotNull('expiration_date')
            ->where('subscription_status', SubscriptionStatus::Active);
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

    /**
     * @return Attribute<ExpirationUrgency|null, never>
     */
    protected function expirationUrgency(): Attribute
    {
        return Attribute::make(
            get: function (): ?ExpirationUrgency {
                if ($this->expiration_date === null) {
                    return null;
                }

                $today = Carbon::today();

                return match (true) {
                    $this->expiration_date->lt($today) => ExpirationUrgency::Expired,
                    $this->expiration_date->lte($today->copy()->addDays(30)) => ExpirationUrgency::ExpiringSoon,
                    default => ExpirationUrgency::Upcoming,
                };
            },
        );
    }
}
```

- [ ] **Step 6: Add the `subscription()` factory state**

Replace the full contents of `database/factories/InvoiceRowFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceRow>
 */
class InvoiceRowFactory extends Factory
{
    protected $model = InvoiceRow::class;

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return static
     */
    public function subscription(): static
    {
        return $this->state(fn (): array => [
            'expiration_date' => fake()->dateTimeBetween('-60 days', '+120 days')->format('Y-m-d'),
        ]);
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/InvoiceRowTest.php`
Expected: PASS

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_13_150000_add_expiration_date_and_subscription_status_to_invoice_rows_table.php app/Enums/SubscriptionStatus.php app/Enums/ExpirationUrgency.php app/Models/InvoiceRow.php database/factories/InvoiceRowFactory.php tests/Feature/InvoiceRowTest.php
git commit -m "feat: add expiration tracking fields to invoice rows"
```

---

### Task 2: Accept `expiration_date` when creating/updating invoice rows

**Files:**
- Modify: `app/Http/Requests/StoreInvoiceRequest.php`
- Modify: `app/Http/Requests/UpdateInvoiceRequest.php`
- Test: `tests/Feature/InvoiceTest.php` (append tests)

**Interfaces:**
- Consumes: nothing new from Task 1 directly (validation only), but relies on the `expiration_date`/`subscription_status` columns existing (Task 1).
- Produces: `rows.*.expiration_date` becomes an accepted, nullable-date request field on `POST /invoices` and `PUT /invoices/{invoice}`. `InvoiceController::store()`/`update()` need **no code changes** — both already spread validated row data generically (`$request->safe()->input('rows')` / `collect($row)->except('id')->all()`), so once the rule exists the field flows through automatically. `subscription_status` remains unreachable from these requests since no rule is defined for it (Laravel's `safe()`/`validated()` only returns keys that have rules).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/InvoiceTest.php`:

```php
test('creating an invoice persists an optional row expiration date', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'it',
        'rows' => [
            ['description' => 'Hosting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22, 'expiration_date' => '2027-01-15'],
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 50, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('invoices.index'));

    $invoice = Invoice::query()->where('company_id', $company->id)->firstOrFail();
    expect($invoice->rows->firstWhere('description', 'Hosting')->expiration_date->format('Y-m-d'))->toBe('2027-01-15');
    expect($invoice->rows->firstWhere('description', 'Consulting')->expiration_date)->toBeNull();
});

test('creating an invoice ignores a client-supplied subscription_status', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'it',
        'rows' => [
            ['description' => 'Hosting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22, 'expiration_date' => '2027-01-15', 'subscription_status' => 'cancelled'],
        ],
    ]);

    $invoice = Invoice::query()->where('company_id', $company->id)->firstOrFail();
    expect($invoice->rows->first()->subscription_status)->toBe(\App\Enums\SubscriptionStatus::Active);
});

test('updating an invoice persists a row expiration date change', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $row = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => null]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('invoices.update', $invoice), [
        'number' => $invoice->number,
        'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
        'paid' => $invoice->paid,
        'customer_id' => $invoice->customer_id,
        'language' => $invoice->language,
        'rows' => [
            ['id' => $row->id, 'description' => $row->description, 'quantity' => $row->quantity, 'price' => $row->price, 'vat_rate' => $row->vat_rate, 'expiration_date' => '2027-03-01'],
        ],
    ]);

    $response->assertSessionHasNoErrors();
    expect($row->fresh()->expiration_date->format('Y-m-d'))->toBe('2027-03-01');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="row expiration"`
Expected: FAIL — validation strips `expiration_date` (not yet a rule), so it never persists.

- [ ] **Step 3: Add the validation rule to both requests**

In `app/Http/Requests/StoreInvoiceRequest.php`, add to the `rules()` array, right after `rows.*.vat_rate`:

```php
            'rows.*.expiration_date' => ['nullable', 'date'],
```

In `app/Http/Requests/UpdateInvoiceRequest.php`, add the same line in the same position.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/InvoiceTest.php`
Expected: PASS (full file, to confirm no regressions in the existing row-diff tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/StoreInvoiceRequest.php app/Http/Requests/UpdateInvoiceRequest.php tests/Feature/InvoiceTest.php
git commit -m "feat: accept an optional expiration date on invoice rows"
```

---

### Task 3: `SubscriptionController` — renew, cancel row, cancel group

**Files:**
- Create: `app/Http/Controllers/SubscriptionController.php`
- Create: `routes/subscriptions.php`
- Modify: `routes/web.php:16` (add the new `require` line)
- Test: `tests/Feature/SubscriptionTest.php` (new file)

**Interfaces:**
- Consumes: `InvoiceRow::scopeSubscriptions()` and `App\Enums\SubscriptionStatus` (Task 1); `ScopesToCurrentCompany::authorizeCurrentCompany(Model $record): void` and `redirectToCreateCompany()` (existing trait, `app/Http/Controllers/Concerns/ScopesToCurrentCompany.php`).
- Produces: named routes `subscriptions.renew` (`POST /subscriptions/{invoice}/renew`), `subscriptions.cancel` (`POST /subscriptions/{invoice}/cancel`), `invoice-rows.cancel` (`POST /invoice-rows/{invoiceRow}/cancel`) — consumed by the Dashboard widget in Task 6 via the generated `SubscriptionController` Wayfinder actions.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SubscriptionTest.php`:

```php
<?php

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;
use Illuminate\Support\Carbon;

test('renewing a group creates a new invoice with copied rows one year later and cancels the source rows', function () {
    Carbon::setTestNow('2026-08-13');
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $customer = Customer::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'language' => 'it']);
    $subscriptionRow = InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Hosting',
        'quantity' => 1,
        'price' => 100,
        'vat_rate' => 22,
        'expiration_date' => '2026-08-01',
    ]);
    $plainRow = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => null]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('subscriptions.renew', $invoice));

    $newInvoice = Invoice::query()->where('id', '!=', $invoice->id)->firstOrFail();
    $response->assertRedirect(route('invoices.edit', $newInvoice));

    expect($newInvoice->company_id)->toBe($company->id);
    expect($newInvoice->customer_id)->toBe($customer->id);
    expect($newInvoice->language)->toBe('it');
    expect($newInvoice->paid)->toBeFalse();
    expect($newInvoice->rows)->toHaveCount(1);

    $newRow = $newInvoice->rows->first();
    expect($newRow->description)->toBe('Hosting');
    expect($newRow->expiration_date->format('Y-m-d'))->toBe('2027-08-01');
    expect($newRow->subscription_status)->toBe(SubscriptionStatus::Active);

    expect($subscriptionRow->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($plainRow->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);

    Carbon::setTestNow();
});

test('renewing a group with no active subscription rows returns a 404', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => null]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('subscriptions.renew', $invoice));

    $response->assertNotFound();
});

test('renewing a group from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-09-01']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('subscriptions.renew', $invoice));

    $response->assertForbidden();
});

test('cancelling a single row marks only that row as cancelled', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $row = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-09-01']);
    $otherRow = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-09-01']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('invoice-rows.cancel', $row));

    $response->assertRedirect(route('dashboard'));
    expect($row->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($otherRow->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

test('cancelling a row from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);
    $row = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-09-01']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('invoice-rows.cancel', $row));

    $response->assertForbidden();
});

test('cancelling a group marks all its active subscription rows as cancelled, leaving plain rows alone', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $rowA = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-09-01']);
    $rowB = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-10-01']);
    $plainRow = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => null]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('subscriptions.cancel', $invoice));

    $response->assertRedirect(route('dashboard'));
    expect($rowA->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($rowB->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($plainRow->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

test('cancelling a group from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-09-01']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('subscriptions.cancel', $invoice));

    $response->assertForbidden();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/SubscriptionTest.php`
Expected: FAIL — route `subscriptions.renew` (and siblings) don't exist yet.

- [ ] **Step 3: Add the routes**

Create `routes/subscriptions.php`:

```php
<?php

use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('subscriptions/{invoice}/renew', [SubscriptionController::class, 'renew'])->name('subscriptions.renew');
    Route::post('subscriptions/{invoice}/cancel', [SubscriptionController::class, 'cancelGroup'])->name('subscriptions.cancel');
    Route::post('invoice-rows/{invoiceRow}/cancel', [SubscriptionController::class, 'cancelRow'])->name('invoice-rows.cancel');
});
```

In `routes/web.php`, add the require line after `require __DIR__.'/products.php';`:

```php
require __DIR__.'/subscriptions.php';
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/SubscriptionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Concerns\ScopesToCurrentCompany;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SubscriptionController extends Controller
{
    use ScopesToCurrentCompany;

    public function renew(Invoice $invoice): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoice);

        $rows = $invoice->rows()->subscriptions()->get();

        abort_if($rows->isEmpty(), 404);

        $newInvoice = DB::transaction(function () use ($invoice, $rows) {
            $newInvoice = Invoice::query()->create([
                'company_id' => $invoice->company_id,
                'customer_id' => $invoice->customer_id,
                'invoice_date' => now()->format('Y-m-d'),
                'paid' => false,
                'language' => $invoice->language,
            ]);

            foreach ($rows as $row) {
                $newInvoice->rows()->create([
                    'description' => $row->description,
                    'quantity' => $row->quantity,
                    'price' => $row->price,
                    'vat_rate' => $row->vat_rate,
                    'expiration_date' => $row->expiration_date->copy()->addYear(),
                    'subscription_status' => SubscriptionStatus::Active,
                ]);
            }

            InvoiceRow::query()->whereIn('id', $rows->pluck('id'))->update([
                'subscription_status' => SubscriptionStatus::Cancelled,
            ]);

            return $newInvoice;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Renewal invoice created.')]);

        return to_route('invoices.edit', $newInvoice);
    }

    public function cancelRow(InvoiceRow $invoiceRow): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoiceRow->invoice);

        $invoiceRow->update(['subscription_status' => SubscriptionStatus::Cancelled]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service marked as not renewing.')]);

        return to_route('dashboard');
    }

    public function cancelGroup(Invoice $invoice): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoice);

        $invoice->rows()->subscriptions()->update(['subscription_status' => SubscriptionStatus::Cancelled]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('All services marked as not renewing.')]);

        return to_route('dashboard');
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/SubscriptionTest.php`
Expected: PASS

- [ ] **Step 6: Regenerate Wayfinder actions**

Run: `php artisan wayfinder:generate --no-interaction`
Expected: Generates/updates `resources/js/actions/App/Http/Controllers/SubscriptionController.ts` and `resources/js/routes/subscriptions/index.ts` (or equivalent) — needed by Task 6.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SubscriptionController.php routes/subscriptions.php routes/web.php tests/Feature/SubscriptionTest.php resources/js/actions resources/js/routes
git commit -m "feat: add subscription renew/cancel endpoints"
```

---

### Task 4: Dashboard aggregation — KPIs and invoice-grouped subscriptions

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Test: `tests/Feature/DashboardTest.php` (append tests)

**Interfaces:**
- Consumes: `InvoiceRow::scopeSubscriptions()`, `InvoiceRow->expiration_urgency` (Task 1); `App\Support\CurrentCompany::resolve(): ?Company` (existing).
- Produces: Inertia prop `subscriptions: { expiredCount: number, expiringSoonCount: number, groups: Array<{ invoice_id: string, invoice_number: string, customer_name: string|null, status: 'expired'|'expiring_soon'|'upcoming', total: number, rows: Array<{ id: string, description: string, price: number, quantity: number, expiration_date: string, urgency: 'expired'|'expiring_soon'|'upcoming' }> }> }` on the `Dashboard` page — consumed by `SubscriptionsWidget.vue` in Task 6.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DashboardTest.php` (add `use App\Enums\SubscriptionStatus;`, `use App\Models\Customer;`, `use App\Models\Invoice;`, `use App\Models\InvoiceRow;`, `use Illuminate\Support\Carbon;` to the existing `use` block at the top):

```php
test('dashboard shares subscription KPIs and invoice-grouped rows scoped to the current company', function () {
    Carbon::setTestNow('2026-08-13');
    $user = User::factory()->create();
    $company = Company::factory()->create(['is_default' => true]);
    $otherCompany = Company::factory()->create();
    $customer = Customer::factory()->create(['name' => 'Acme Srl']);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'description' => 'Domain', 'expiration_date' => '2026-08-01']);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'description' => 'Hosting', 'expiration_date' => '2026-08-20']);

    $otherInvoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);
    InvoiceRow::factory()->create(['invoice_id' => $otherInvoice->id, 'expiration_date' => '2026-08-01']);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('subscriptions.expiredCount', 1)
        ->where('subscriptions.expiringSoonCount', 1)
        ->has('subscriptions.groups', 1)
        ->where('subscriptions.groups.0.invoice_id', $invoice->id)
        ->where('subscriptions.groups.0.customer_name', 'Acme Srl')
        ->where('subscriptions.groups.0.status', 'expired')
        ->has('subscriptions.groups.0.rows', 2)
    );

    Carbon::setTestNow();
});

test('dashboard excludes cancelled subscription rows', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['is_default' => true]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'expiration_date' => '2026-08-01',
        'subscription_status' => SubscriptionStatus::Cancelled,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('subscriptions.expiredCount', 0)
        ->has('subscriptions.groups', 0)
    );
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="dashboard shares subscription"`
Expected: FAIL — `subscriptions` prop doesn't exist yet.

- [ ] **Step 3: Implement the controller**

Replace the full contents of `app/Http/Controllers/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\ExpirationUrgency;
use App\Models\InvoiceRow;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $currentCompanyId = CurrentCompany::resolve()?->id;

        $rows = InvoiceRow::query()
            ->subscriptions()
            ->whereHas('invoice', fn ($query) => $query->where('company_id', $currentCompanyId))
            ->with('invoice.customer')
            ->get();

        $groups = $rows
            ->groupBy('invoice_id')
            ->map(function ($rows) {
                $invoice = $rows->first()->invoice;
                $urgencies = $rows->map->expiration_urgency;

                return [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                    'customer_name' => $invoice->customer?->name,
                    'status' => match (true) {
                        $urgencies->contains(ExpirationUrgency::Expired) => ExpirationUrgency::Expired->value,
                        $urgencies->contains(ExpirationUrgency::ExpiringSoon) => ExpirationUrgency::ExpiringSoon->value,
                        default => ExpirationUrgency::Upcoming->value,
                    },
                    'total' => (float) $rows->sum(fn (InvoiceRow $row): float => $row->price * $row->quantity),
                    'rows' => $rows->map(fn (InvoiceRow $row): array => [
                        'id' => $row->id,
                        'description' => $row->description,
                        'price' => $row->price,
                        'quantity' => $row->quantity,
                        'expiration_date' => $row->expiration_date->format('Y-m-d'),
                        'urgency' => $row->expiration_urgency->value,
                    ])->values(),
                ];
            })
            ->values();

        return Inertia::render('Dashboard', [
            'subscriptions' => [
                'expiredCount' => $rows->filter(fn (InvoiceRow $row): bool => $row->expiration_urgency === ExpirationUrgency::Expired)->count(),
                'expiringSoonCount' => $rows->filter(fn (InvoiceRow $row): bool => $row->expiration_urgency === ExpirationUrgency::ExpiringSoon)->count(),
                'groups' => $groups,
            ],
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/DashboardTest.php`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/DashboardController.php tests/Feature/DashboardTest.php
git commit -m "feat: aggregate expiring subscriptions on the dashboard"
```

---

### Task 5: "Data Scadenza" field on the invoice row form

**Files:**
- Modify: `app/Http/Controllers/InvoiceController.php:69-74`
- Modify: `resources/js/pages/invoices/Create.vue`
- Modify: `resources/js/pages/invoices/Edit.vue`
- Modify: `resources/js/lang/en.ts`, `resources/js/lang/it.ts`, `resources/js/lang/es.ts`
- Test: `tests/Feature/InvoiceTest.php` (append a test)

**Interfaces:**
- Consumes: `rows.*.expiration_date` validation accepted by `StoreInvoiceRequest`/`UpdateInvoiceRequest` (Task 2).
- Produces: `InvoiceRowForm` type gains `expiration_date: string | null`, submitted as part of the existing `rows` array on both forms — no new endpoints. Since `InvoiceRowForm` makes the field required (not optional), `InvoiceController::create()`'s duplicate-mapping must also emit it for every row, or the `duplicate` Inertia prop would violate the frontend type.

- [ ] **Step 1: Write the failing test for the duplicate-mapping**

Append to `tests/Feature/InvoiceTest.php`, near the existing `'invoice create page prefills fields from a duplicate source'`-style test (around line 580-619):

```php
test('invoice create page includes the row expiration date when duplicating', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $currentCompany = Company::factory()->create();
    $sourceCompany = Company::factory()->create();
    $source = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'company_id' => $sourceCompany->id,
        'language' => 'en',
    ]);
    InvoiceRow::factory()->create([
        'invoice_id' => $source->id,
        'description' => 'Hosting',
        'expiration_date' => '2026-09-01',
    ]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $currentCompany->id])->get(route('invoices.create', ['duplicate' => $source->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate.rows.0.expiration_date', '2026-09-01')
    );
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="includes the row expiration date when duplicating"`
Expected: FAIL — `duplicate.rows.0.expiration_date` is missing from the response.

- [ ] **Step 3: Update the duplicate-mapping in `InvoiceController::create()`**

In `app/Http/Controllers/InvoiceController.php`, the row-mapping closure (currently lines 69-74):

```php
                'rows' => $source->rows->map(fn (InvoiceRow $row): array => [
                    'description' => $row->description,
                    'quantity' => $row->quantity,
                    'price' => $row->price,
                    'vat_rate' => $row->vat_rate,
                ])->all(),
```

becomes:

```php
                'rows' => $source->rows->map(fn (InvoiceRow $row): array => [
                    'description' => $row->description,
                    'quantity' => $row->quantity,
                    'price' => $row->price,
                    'vat_rate' => $row->vat_rate,
                    'expiration_date' => $row->expiration_date?->format('Y-m-d'),
                ])->all(),
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/InvoiceTest.php`
Expected: PASS (full file, to confirm no regressions)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/InvoiceController.php tests/Feature/InvoiceTest.php
git commit -m "feat: carry the row expiration date over when duplicating an invoice"
```

- [ ] **Step 6: Add the translation key to all three locales**

In `resources/js/lang/en.ts`, inside `invoices.create`, add after `rowVatPlaceholder: 'VAT %',` (around line 409):

```ts
            rowExpirationDate: 'Expiration date',
```

In `resources/js/lang/it.ts`, inside `invoices.create`, add after the `rowVatPlaceholder` line (around line 420):

```ts
            rowExpirationDate: 'Data di scadenza',
```

In `resources/js/lang/es.ts`, inside `invoices.create`, add after the `rowVatPlaceholder` line (around line 426):

```ts
            rowExpirationDate: 'Fecha de vencimiento',
```

- [ ] **Step 7: Update `Create.vue`**

In `resources/js/pages/invoices/Create.vue`:

Change the `InvoiceRowForm` type (line 30-35):

```ts
type InvoiceRowForm = {
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
    expiration_date: string | null;
};
```

Change the `duplicate` prop type's `rows` entry to match (line 44) — it already references `InvoiceRowForm[]`, no change needed there since the type above changed.

Change the default row in `form.rows` (line 64):

```ts
    rows: props.duplicate?.rows ?? [
        { description: '', quantity: 1, price: 0, vat_rate: 0, expiration_date: null },
    ],
```

Change `addRow()` (line 72-75):

```ts
function addRow(): void {
    form.rows.push({
        description: '',
        quantity: 1,
        price: 0,
        vat_rate: 0,
        expiration_date: null,
    });
    selectedProducts.value.push(undefined);
}
```

Widen the grid template on both the header row (line 221) and the row template (line 234) from:

```
grid-cols-[2.5rem_1fr_6rem_8rem_6rem_2.5rem]
```

to:

```
grid-cols-[2.5rem_1fr_6rem_8rem_6rem_8rem_2.5rem]
```

Add a header label in the header row (line 220-229), right after the VAT `<span>` and before the trailing empty `<span>`:

```html
                    <span>{{ t('invoices.create.rowExpirationDate') }}</span>
```

Add the input in the row template (line 231-295), right after the VAT `<div class="grid gap-1">...</div>` block and before the delete `<Button>`:

```html
                    <div class="grid gap-1">
                        <Input
                            v-model="row.expiration_date"
                            type="date"
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.expiration_date`]"
                        />
                    </div>
```

Note: `v-model="row.expiration_date"` (not `.number`) since this is a date string, and the input allows an empty value — a native `<input type="date">` emits `''` when cleared, so also update `submit()`... actually no change needed there since Inertia's form will send `''`; verify the validation rule `nullable|date` rejects `''`. Confirm this concern is resolved in Step 4 below.

- [ ] **Step 8: Update `Edit.vue`**

Apply the equivalent changes to `resources/js/pages/invoices/Edit.vue`:

Change `InvoiceRow` type (line 30-36) to add `expiration_date: string | null;`.

Change `InvoiceRowForm` type (line 50-56) to add `expiration_date: string | null;`.

Change the `form.rows` mapping (line 78-84) to include `expiration_date: row.expiration_date,`.

Change `addRow()` (line 91-94):

```ts
function addRow(): void {
    form.rows.push({
        description: '',
        quantity: 1,
        price: 0,
        vat_rate: 0,
        expiration_date: null,
    });
    selectedProducts.value.push(undefined);
}
```

Widen both grid templates (lines 275 and 288) the same way as in `Create.vue`.

Add the same header `<span>` (after line 281's VAT span) and the same input block (after the VAT input block, before the delete `Button`, around line 339) as in `Create.vue` — identical markup.

- [ ] **Step 9: Handle the empty-date edge case**

A native `<input type="date">` sends `''` (not `null`) when cleared by the user, and `''` fails the `nullable|date` rule (it's not treated as absent). Guard this in `submit()` in both `Create.vue` and `Edit.vue` by normalizing empty strings to `null` right before submitting. In `Create.vue`, change `submit()` (line 106-108):

```ts
function submit(): void {
    form.rows.forEach((row) => {
        row.expiration_date ||= null;
    });
    form.post(InvoiceController.store().url);
}
```

In `Edit.vue`, change `submit()` (line 125-127) the same way:

```ts
function submit(): void {
    form.rows.forEach((row) => {
        row.expiration_date ||= null;
    });
    form.put(InvoiceController.update(props.invoice.id).url);
}
```

- [ ] **Step 10: Verify the backend tests from Task 2 still cover this end-to-end**

Run: `php artisan test --compact tests/Feature/InvoiceTest.php`
Expected: PASS — these already post/put `rows.*.expiration_date` and assert persistence, exercising the same request shape the form now produces.

- [ ] **Step 11: Manually verify in the browser**

Run `npm run dev` (or `composer run dev` if not already running), open an invoice's create/edit page, confirm the "Expiration date" column renders, accepts an optional date, can be cleared, and round-trips correctly after save.

- [ ] **Step 12: Commit**

```bash
git add resources/js/pages/invoices/Create.vue resources/js/pages/invoices/Edit.vue resources/js/lang/en.ts resources/js/lang/it.ts resources/js/lang/es.ts
git commit -m "feat: add an optional expiration date field to invoice rows"
```

---

### Task 6: Dashboard "Scadenziario" widget

**Files:**
- Create: `resources/js/components/dashboard/SubscriptionsWidget.vue`
- Modify: `resources/js/pages/Dashboard.vue`
- Modify: `resources/js/lang/en.ts`, `resources/js/lang/it.ts`, `resources/js/lang/es.ts`

**Interfaces:**
- Consumes: the `subscriptions` Inertia prop shape produced in Task 4; the `SubscriptionController.renew/cancelRow/cancelGroup` Wayfinder actions generated in Task 3.
- Produces: a self-contained `SubscriptionsWidget.vue` component taking `expiredCount: number`, `expiringSoonCount: number`, `groups: SubscriptionGroup[]` as props — no other component depends on it yet.

- [ ] **Step 1: Add translation keys to all three locales**

In `resources/js/lang/en.ts`, replace the existing `dashboard: { title: 'Dashboard' }` block (around line 174-176) with:

```ts
    dashboard: {
        title: 'Dashboard',
        subscriptions: {
            expiredLabel: 'Expired services',
            expiringSoonLabel: 'Expiring in 30 days',
            empty: 'No expiring services.',
            status: {
                expired: 'Expired',
                expiring_soon: 'Expiring soon',
                upcoming: 'Upcoming',
            },
            cancelRow: 'Cancel',
            cancelGroup: 'Cancel group',
            renewGroup: 'Renew group',
            total: 'Total: {amount}',
            confirmCancelRow: 'Mark this service as not renewing?',
            confirmCancelGroup: 'Mark all services in this group as not renewing?',
        },
    },
```

In `resources/js/lang/it.ts`, find the equivalent `dashboard: { title: 'Dashboard' }` block and replace it with:

```ts
    dashboard: {
        title: 'Dashboard',
        subscriptions: {
            expiredLabel: 'Servizi scaduti',
            expiringSoonLabel: 'In scadenza nei prossimi 30 giorni',
            empty: 'Nessun servizio in scadenza.',
            status: {
                expired: 'Scaduto',
                expiring_soon: 'In scadenza',
                upcoming: 'Futuro',
            },
            cancelRow: 'Annulla',
            cancelGroup: 'Annulla gruppo',
            renewGroup: 'Rinnova gruppo',
            total: 'Totale: {amount}',
            confirmCancelRow: 'Contrassegnare questo servizio come non da rinnovare?',
            confirmCancelGroup: 'Contrassegnare tutti i servizi del gruppo come non da rinnovare?',
        },
    },
```

In `resources/js/lang/es.ts`, find the equivalent block and replace it with:

```ts
    dashboard: {
        title: 'Dashboard',
        subscriptions: {
            expiredLabel: 'Servicios caducados',
            expiringSoonLabel: 'Vencen en 30 días',
            empty: 'No hay servicios por vencer.',
            status: {
                expired: 'Caducado',
                expiring_soon: 'Por vencer',
                upcoming: 'Próximo',
            },
            cancelRow: 'Cancelar',
            cancelGroup: 'Cancelar grupo',
            renewGroup: 'Renovar grupo',
            total: 'Total: {amount}',
            confirmCancelRow: '¿Marcar este servicio como no renovable?',
            confirmCancelGroup: '¿Marcar todos los servicios del grupo como no renovables?',
        },
    },
```

- [ ] **Step 2: Create the widget component**

Create `resources/js/components/dashboard/SubscriptionsWidget.vue`:

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SubscriptionController from '@/actions/App/Http/Controllers/SubscriptionController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

type SubscriptionStatus = 'expired' | 'expiring_soon' | 'upcoming';

type SubscriptionRow = {
    id: string;
    description: string;
    price: number;
    quantity: number;
    expiration_date: string;
    urgency: SubscriptionStatus;
};

type SubscriptionGroup = {
    invoice_id: string;
    invoice_number: string;
    customer_name: string | null;
    status: SubscriptionStatus;
    total: number;
    rows: SubscriptionRow[];
};

const props = defineProps<{
    expiredCount: number;
    expiringSoonCount: number;
    groups: SubscriptionGroup[];
}>();

const { t } = useI18n();

const badgeClasses: Record<SubscriptionStatus, string> = {
    expired: 'border-transparent bg-red-600 text-white',
    expiring_soon: 'border-transparent bg-orange-500 text-white',
    upcoming: 'border-transparent bg-green-600 text-white',
};

function formatDate(date: string): string {
    const [year, month, day] = date.split('-');

    return `${day}/${month}/${year}`;
}

function renewGroup(invoiceId: string): void {
    router.post(SubscriptionController.renew(invoiceId).url);
}

function cancelGroup(invoiceId: string): void {
    if (confirm(t('dashboard.subscriptions.confirmCancelGroup'))) {
        router.post(SubscriptionController.cancelGroup(invoiceId).url);
    }
}

function cancelRow(rowId: string): void {
    if (confirm(t('dashboard.subscriptions.confirmCancelRow'))) {
        router.post(SubscriptionController.cancelRow(rowId).url);
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <Card>
                <CardHeader>
                    <CardTitle
                        class="text-sm font-medium text-muted-foreground"
                    >
                        {{ t('dashboard.subscriptions.expiredLabel') }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-3xl font-bold text-red-600">
                        {{ props.expiredCount }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle
                        class="text-sm font-medium text-muted-foreground"
                    >
                        {{ t('dashboard.subscriptions.expiringSoonLabel') }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-3xl font-bold text-orange-500">
                        {{ props.expiringSoonCount }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <p
            v-if="props.groups.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{ t('dashboard.subscriptions.empty') }}
        </p>

        <Card v-for="group in props.groups" :key="group.invoice_id">
            <CardHeader class="flex flex-row items-center justify-between">
                <div>
                    <CardTitle>{{ group.customer_name ?? '—' }}</CardTitle>
                    <p class="text-sm text-muted-foreground">
                        {{ group.invoice_number }}
                    </p>
                </div>
                <Badge :class="badgeClasses[group.status]">
                    {{ t(`dashboard.subscriptions.status.${group.status}`) }}
                </Badge>
            </CardHeader>
            <CardContent class="space-y-3">
                <div
                    v-for="row in group.rows"
                    :key="row.id"
                    class="flex items-center justify-between gap-2 text-sm"
                >
                    <span>{{ row.description }}</span>
                    <span class="text-muted-foreground">{{
                        formatDate(row.expiration_date)
                    }}</span>
                    <span>{{ row.price.toFixed(2) }}</span>
                    <Button variant="ghost" size="sm" @click="cancelRow(row.id)">
                        {{ t('dashboard.subscriptions.cancelRow') }}
                    </Button>
                </div>

                <div class="flex items-center justify-between border-t pt-3">
                    <span class="font-medium">
                        {{
                            t('dashboard.subscriptions.total', {
                                amount: group.total.toFixed(2),
                            })
                        }}
                    </span>
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="cancelGroup(group.invoice_id)"
                        >
                            {{ t('dashboard.subscriptions.cancelGroup') }}
                        </Button>
                        <Button size="sm" @click="renewGroup(group.invoice_id)">
                            {{ t('dashboard.subscriptions.renewGroup') }}
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
```

- [ ] **Step 3: Wire the widget into the Dashboard page**

Replace the full contents of `resources/js/pages/Dashboard.vue`:

```vue
<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SubscriptionsWidget from '@/components/dashboard/SubscriptionsWidget.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

type SubscriptionStatus = 'expired' | 'expiring_soon' | 'upcoming';

type SubscriptionRow = {
    id: string;
    description: string;
    price: number;
    quantity: number;
    expiration_date: string;
    urgency: SubscriptionStatus;
};

type SubscriptionGroup = {
    invoice_id: string;
    invoice_number: string;
    customer_name: string | null;
    status: SubscriptionStatus;
    total: number;
    rows: SubscriptionRow[];
};

const props = defineProps<{
    subscriptions: {
        expiredCount: number;
        expiringSoonCount: number;
        groups: SubscriptionGroup[];
    };
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        {
            title: t('dashboard.title'),
            href: dashboard(),
        },
    ] satisfies BreadcrumbItem[],
});
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
        <SubscriptionsWidget
            :expired-count="props.subscriptions.expiredCount"
            :expiring-soon-count="props.subscriptions.expiringSoonCount"
            :groups="props.subscriptions.groups"
        />
    </div>
</template>
```

- [ ] **Step 4: Regenerate Wayfinder actions if needed**

Run: `php artisan wayfinder:generate --no-interaction`
Expected: `resources/js/actions/App/Http/Controllers/SubscriptionController.ts` exists with `renew`, `cancelRow`, `cancelGroup` exports (should already exist from Task 3, but re-run is a no-op safety check).

- [ ] **Step 5: Run the full backend test suite**

Run: `php artisan test --compact`
Expected: PASS — no backend files changed in this task, this just confirms nothing else broke.

- [ ] **Step 6: Manually verify in the browser**

Run `npm run dev` (or `composer run dev`). Create an invoice with two rows carrying expiration dates (one in the past, one within 30 days) for the same customer, then open the Dashboard: confirm the KPI counts, the grouped card, the badge colors (red/orange), and that "Rinnova gruppo" creates a new invoice (redirecting to its edit page) while "Annulla" (row) and "Annulla gruppo" remove entries from the widget after confirming the dialog.

- [ ] **Step 7: Commit**

```bash
git add resources/js/components/dashboard/SubscriptionsWidget.vue resources/js/pages/Dashboard.vue resources/js/lang/en.ts resources/js/lang/it.ts resources/js/lang/es.ts
git commit -m "feat: add the scadenziario widget to the dashboard"
```

---

## Out of scope (per spec)

- Changes to the PDF/preview invoice template.
- Email/notification reminders.
- Editing `subscription_status` from the invoice row form.
- A denormalized `company_id` column on `invoice_rows`.
