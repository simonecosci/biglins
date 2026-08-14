# Preventivi (Estimations) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a company-scoped "Estimation" (Preventivo) entity — markdown proposal body, product/service line items, N attachments, PDF preview/export, a ZIP bundle (PDF + attachments), and an explicit conversion into a real Invoice once accepted.

**Architecture:** Mirrors the existing `Invoice`/`InvoiceRow` CRUD stack (UUID models, `ScopesToCurrentCompany`, Inertia+Vue pages, dompdf template) almost file-for-file, plus two new pieces with no existing precedent: a generic polymorphic `Attachment` model for file uploads, and a markdown render/preview round-trip using the already-installed `league/commonmark`.

**Tech Stack:** Laravel 13, Inertia v3 + Vue 3, `barryvdh/laravel-dompdf`, `league/commonmark`, native PHP `ZipArchive`, Pest 5.

**Spec:** `docs/superpowers/specs/2026-08-14-estimations-design.md`

## Global Constraints

- New tables/models: `estimations`, `estimation_rows`, `attachments` (generic polymorphic, reusable beyond this feature).
- `Estimation.number` uses its own per-company sequence, same `YYYY-NNNN` shape as `Invoice.number`, but a separate counter — never mixed with invoice numbers.
- `Estimation.customer_id` and `company_id` are immutable after creation (never accepted on update).
- Attachments: disk `local`, allowed extensions `pdf, jpg, jpeg, png, doc, docx, rtf, md`, max 10MB (10240 KB) each — validated by a custom extension check, not Laravel's `mimes:` rule (unreliable for `.md`).
- No email sending in this iteration; the ZIP is a manual download only.
- No persisted "expired" status — `isExpired` is a computed, non-persisted accessor.
- Editing is blocked once `invoice_id` is set (converted); deletion is blocked when `status === Accepted` or `invoice_id` is set.
- `preview()`/`pdf()`/`zip()` follow the exact same convention `InvoiceController::preview()`/`pdf()` already use: **no** `authorizeCurrentCompany()` call (any authenticated user with the UUID can view/download — consistent with the existing invoice behavior, not a new gap). Mutating actions (`store`, `update`, `destroy`, `convertToInvoice`, attachment `store`/`destroy`) **do** call `authorizeCurrentCompany()`.
- Every PHP change ends with `vendor/bin/pint --dirty --format agent`; every task that touches `app/` or `database/` should pass `vendor/bin/phpstan analyse --memory-limit=512M` before moving on.
- After adding/changing routes or controller actions, run `php artisan wayfinder:generate` so `resources/js/actions/App/Http/Controllers/EstimationController.ts` (and friends) stay in sync before writing the Vue code that imports them.

---

## File Structure

**Backend (new files):**
- `database/migrations/..._create_estimations_table.php`
- `database/migrations/..._create_estimation_rows_table.php`
- `database/migrations/..._create_attachments_table.php`
- `app/Enums/EstimationStatus.php`
- `app/Models/Estimation.php`, `app/Models/EstimationRow.php`, `app/Models/Attachment.php`
- `database/factories/EstimationFactory.php`, `EstimationRowFactory.php`, `AttachmentFactory.php`
- `app/Http/Requests/StoreEstimationRequest.php`, `UpdateEstimationRequest.php`, `StoreEstimationAttachmentRequest.php`
- `app/Http/Controllers/EstimationController.php`, `EstimationAttachmentController.php`
- `app/Support/MarkdownRenderer.php`
- `routes/estimations.php` (required from `routes/web.php`)
- `resources/views/estimations/template.blade.php`
- `resources/lang/{en,it,es}/estimation.php`
- `tests/Feature/EstimationTest.php`, `EstimationRowTest.php`, `EstimationTemplateTest.php`, `EstimationAttachmentTest.php`, `EstimationConversionTest.php`, `EstimationZipTest.php`

**Frontend (new files):**
- `resources/js/pages/estimations/Index.vue`, `Create.vue`, `Edit.vue`
- `resources/js/components/MarkdownField.vue`
- `resources/js/lib/estimationStatus.ts`

**Modified files:**
- `routes/web.php` (require `estimations.php`)
- `resources/js/components/AppSidebar.vue` (nav entry)
- `resources/js/lang/en.ts`, `it.ts`, `es.ts` (new `nav.estimations`, `estimations.*`, `markdownField.*` keys)

---

### Task 1: `Estimation` model, migration, enum, factory

**Files:**
- Create: `database/migrations/2026_08_14_130000_create_estimations_table.php`
- Create: `app/Enums/EstimationStatus.php`
- Create: `app/Models/Estimation.php`
- Create: `database/factories/EstimationFactory.php`
- Test: `tests/Feature/EstimationTest.php`

**Interfaces:**
- Produces: `Estimation` model (`id`, `company_id`, `customer_id`, `number`, `estimation_date`, `expiration_date`, `language`, `body`, `status: EstimationStatus`, `invoice_id`), `Estimation::nextNumber(string $companyId, ?string $year = null): string`, `Estimation::company()`/`customer()` (`BelongsTo`), computed `isExpired` accessor. `EstimationStatus::{Pending,Accepted,Rejected}`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\EstimationStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

test('estimation factory creates an estimation with a uuid primary key', function () {
    $estimation = Estimation::factory()->create();

    expect($estimation->id)->toBeString();
    expect(strlen($estimation->id))->toBe(36);
    expect($estimation->customer)->toBeInstanceOf(Customer::class);
    expect($estimation->company)->toBeInstanceOf(Company::class);
    expect($estimation->status)->toBe(EstimationStatus::Pending);
});

test('an estimation requires a company_id at the database level', function () {
    expect(fn () => Estimation::factory()->create(['company_id' => null]))
        ->toThrow(QueryException::class);
});

test('first estimation of the year is numbered 0001', function () {
    Carbon::setTestNow('2026-01-15');
    $company = Company::factory()->create();

    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'number' => null]);

    expect($estimation->number)->toBe('2026-0001');

    Carbon::setTestNow();
});

test('subsequent estimations in the same year increment the sequence', function () {
    Carbon::setTestNow('2026-01-15');
    $company = Company::factory()->create();

    Estimation::factory()->create(['company_id' => $company->id, 'number' => null]);
    $second = Estimation::factory()->create(['company_id' => $company->id, 'number' => null]);

    expect($second->number)->toBe('2026-0002');

    Carbon::setTestNow();
});

test('estimation numbering is independent per company and independent from invoice numbering', function () {
    Carbon::setTestNow('2026-01-15');
    $company = Company::factory()->create();
    Estimation::factory()->create(['company_id' => $company->id, 'number' => null]);
    $otherCompany = Company::factory()->create();

    $firstForOther = Estimation::factory()->create(['company_id' => $otherCompany->id, 'number' => null]);

    expect($firstForOther->number)->toBe('2026-0001');

    Carbon::setTestNow();
});

test('estimation number must be unique per company at the database level', function () {
    $company = Company::factory()->create();
    Estimation::factory()->create(['company_id' => $company->id, 'number' => '2026-0001']);

    expect(fn () => Estimation::factory()->create(['company_id' => $company->id, 'number' => '2026-0001']))
        ->toThrow(QueryException::class);
});

test('isExpired is false for a pending estimation with a future expiration date', function () {
    Carbon::setTestNow('2026-08-14');
    $estimation = Estimation::factory()->create(['status' => EstimationStatus::Pending, 'expiration_date' => '2026-09-01']);

    expect($estimation->isExpired)->toBeFalse();

    Carbon::setTestNow();
});

test('isExpired is true for a pending estimation with a past expiration date', function () {
    Carbon::setTestNow('2026-08-14');
    $estimation = Estimation::factory()->create(['status' => EstimationStatus::Pending, 'expiration_date' => '2026-08-01']);

    expect($estimation->isExpired)->toBeTrue();

    Carbon::setTestNow();
});

test('isExpired is false for an accepted estimation even with a past expiration date', function () {
    Carbon::setTestNow('2026-08-14');
    $estimation = Estimation::factory()->create(['status' => EstimationStatus::Accepted, 'expiration_date' => '2026-08-01']);

    expect($estimation->isExpired)->toBeFalse();

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/EstimationTest.php`
Expected: FAIL — class `App\Models\Estimation` not found.

- [ ] **Step 3: Create the enum**

```php
<?php

namespace App\Enums;

enum EstimationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
```

- [ ] **Step 4: Create the migration**

```bash
php artisan make:migration create_estimations_table --no-interaction
```

Replace its contents:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->string('number');
            $table->date('estimation_date');
            $table->date('expiration_date');
            $table->string('language');
            $table->longText('body')->nullable();
            $table->string('status')->default('pending');
            $table->foreignUuid('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimations');
    }
};
```

- [ ] **Step 5: Create the model**

```php
<?php

namespace App\Models;

use App\Enums\EstimationStatus;
use Database\Factories\EstimationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string $customer_id
 * @property string $number
 * @property Carbon $estimation_date
 * @property Carbon $expiration_date
 * @property string $language
 * @property string|null $body
 * @property EstimationStatus $status
 * @property string|null $invoice_id
 * @property-read bool $is_expired
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['company_id', 'customer_id', 'estimation_date', 'expiration_date', 'language', 'body', 'status'])]
class Estimation extends Model
{
    /** @use HasFactory<EstimationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimation_date' => 'date:Y-m-d',
            'expiration_date' => 'date:Y-m-d',
            'status' => EstimationStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Estimation $estimation): void {
            if (! $estimation->number) {
                $estimation->number = static::nextNumber($estimation->company_id);
            }
        });
    }

    public static function nextNumber(string $companyId, ?string $year = null): string
    {
        $year ??= now()->format('Y');

        $lastNumber = static::query()
            ->where('company_id', $companyId)
            ->where('number', 'like', "{$year}-%")
            ->orderByDesc('number')
            ->value('number');

        $sequence = $lastNumber
            ? ((int) substr($lastNumber, strlen($year) + 1)) + 1
            : 1;

        return sprintf('%s-%04d', $year, $sequence);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isExpired(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->status === EstimationStatus::Pending
                && $this->expiration_date->lt(Carbon::today()),
        );
    }
}
```

- [ ] **Step 6: Create the factory**

```php
<?php

namespace Database\Factories;

use App\Enums\EstimationStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Estimation>
 */
class EstimationFactory extends Factory
{
    protected $model = Estimation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => null,
            'company_id' => Company::factory(),
            'customer_id' => fn (array $attributes) => Customer::factory()->create(['company_id' => $attributes['company_id']])->id,
            'estimation_date' => fake()->dateTimeBetween('-2 months', 'now')->format('Y-m-d'),
            'expiration_date' => fake()->dateTimeBetween('now', '+2 months')->format('Y-m-d'),
            'language' => fake()->randomElement(['it', 'en', 'es']),
            'body' => fake()->optional()->paragraphs(3, true),
            'status' => EstimationStatus::Pending,
        ];
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/EstimationTest.php`
Expected: PASS (8 tests)

- [ ] **Step 8: Format and check types**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=512M
```

- [ ] **Step 9: Commit**

```bash
git add database/migrations app/Enums/EstimationStatus.php app/Models/Estimation.php database/factories/EstimationFactory.php tests/Feature/EstimationTest.php
git commit -m "feat: add Estimation model, migration, and factory"
```

---

### Task 2: `EstimationRow` model, migration, factory, and totals

**Files:**
- Create: `database/migrations/2026_08_14_130100_create_estimation_rows_table.php`
- Create: `app/Models/EstimationRow.php`
- Create: `database/factories/EstimationRowFactory.php`
- Modify: `app/Models/Estimation.php` (add `rows()`, `subtotal`, `vatTotal`, `total`)
- Test: `tests/Feature/EstimationRowTest.php` (new file), append to `tests/Feature/EstimationTest.php`

**Interfaces:**
- Consumes: `Estimation` from Task 1.
- Produces: `EstimationRow` model (`id`, `estimation_id`, `description`, `quantity`, `price`, `vat_rate`, `note`), `EstimationRow::total` accessor, `Estimation::rows(): HasMany<EstimationRow>`, `Estimation::subtotal`/`vatTotal`/`total` accessors.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/EstimationRowTest.php`:

```php
<?php

use App\Models\EstimationRow;

test('estimation row total accessor adds vat to the price', function () {
    $row = EstimationRow::factory()->create(['quantity' => 1, 'price' => 100, 'vat_rate' => 22]);

    expect((float) $row->total)->toEqual(122.0);
});

test('estimation row total accessor multiplies price by quantity before applying vat', function () {
    $row = EstimationRow::factory()->create(['quantity' => 2, 'price' => 100, 'vat_rate' => 22]);

    expect((float) $row->total)->toEqual(244.0);
});
```

Append to `tests/Feature/EstimationTest.php`:

```php
test('estimation has many rows and rows are deleted when the estimation is deleted', function () {
    $estimation = Estimation::factory()->create();
    EstimationRow::factory()->count(2)->create(['estimation_id' => $estimation->id]);

    expect($estimation->rows)->toHaveCount(2);

    $estimation->delete();

    expect(EstimationRow::query()->where('estimation_id', $estimation->id)->count())->toBe(0);
});

test('estimation total accessors sum its rows', function () {
    $estimation = Estimation::factory()->create();
    EstimationRow::factory()->create(['estimation_id' => $estimation->id, 'quantity' => 1, 'price' => 100, 'vat_rate' => 22]);
    EstimationRow::factory()->create(['estimation_id' => $estimation->id, 'quantity' => 1, 'price' => 50, 'vat_rate' => 10]);

    expect((float) $estimation->subtotal)->toEqual(150.0);
    expect((float) $estimation->vat_total)->toEqual(27.0);
    expect((float) $estimation->total)->toEqual(177.0);
});
```

Add `use App\Models\EstimationRow;` to the top of `tests/Feature/EstimationTest.php`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/EstimationRowTest.php tests/Feature/EstimationTest.php`
Expected: FAIL — class `App\Models\EstimationRow` not found.

- [ ] **Step 3: Create the migration**

```bash
php artisan make:migration create_estimation_rows_table --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimation_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('estimation_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('price', 10, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimation_rows');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models;

use Database\Factories\EstimationRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $estimation_id
 * @property string $description
 * @property float $quantity
 * @property float $price
 * @property float $vat_rate
 * @property string|null $note
 * @property-read float $total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['estimation_id', 'description', 'quantity', 'price', 'vat_rate', 'note'])]
class EstimationRow extends Model
{
    /** @use HasFactory<EstimationRowFactory> */
    use HasFactory, HasUuids;

    /**
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
     * @return BelongsTo<Estimation, $this>
     */
    public function estimation(): BelongsTo
    {
        return $this->belongsTo(Estimation::class);
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

- [ ] **Step 5: Create the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Estimation;
use App\Models\EstimationRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EstimationRow>
 */
class EstimationRowFactory extends Factory
{
    protected $model = EstimationRow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estimation_id' => Estimation::factory(),
            'description' => fake()->sentence(4),
            'quantity' => fake()->numberBetween(1, 5),
            'price' => fake()->randomFloat(2, 10, 1000),
            'vat_rate' => fake()->randomElement([22, 10, 0]),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
```

- [ ] **Step 6: Add `rows()`, `subtotal`, `vatTotal`, `total` to `Estimation`**

In `app/Models/Estimation.php`, add the `$appends` property, the `rows()` relation, and the three accessors:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```

```php
    /**
     * @var list<string>
     */
    protected $appends = ['subtotal', 'vat_total', 'total'];
```

```php
    /**
     * @return HasMany<EstimationRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(EstimationRow::class);
    }

    /**
     * @return Attribute<float, never>
     */
    protected function subtotal(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->rows->sum(
                fn (EstimationRow $row): float => (float) $row->price * (float) $row->quantity
            ),
        );
    }

    /**
     * @return Attribute<float, never>
     */
    protected function vatTotal(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->rows->sum(function (EstimationRow $row): float {
                $lineTotal = (float) $row->price * (float) $row->quantity;

                return $lineTotal * (float) $row->vat_rate / 100;
            }),
        );
    }

    /**
     * @return Attribute<float, never>
     */
    protected function total(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->subtotal + $this->vat_total,
        );
    }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/EstimationRowTest.php tests/Feature/EstimationTest.php`
Expected: PASS

- [ ] **Step 8: Format and check types**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=512M
```

- [ ] **Step 9: Commit**

```bash
git add database/migrations app/Models/Estimation.php app/Models/EstimationRow.php database/factories/EstimationRowFactory.php tests/Feature/EstimationRowTest.php tests/Feature/EstimationTest.php
git commit -m "feat: add EstimationRow model and wire estimation totals"
```

---

### Task 3: `Attachment` model, migration, factory, and `Estimation::attachments()`

**Files:**
- Create: `database/migrations/2026_08_14_130200_create_attachments_table.php`
- Create: `app/Models/Attachment.php`
- Create: `database/factories/AttachmentFactory.php`
- Modify: `app/Models/Estimation.php` (add `attachments()`)
- Test: append to `tests/Feature/EstimationTest.php`, create `tests/Feature/AttachmentTest.php`

**Interfaces:**
- Consumes: `Estimation` from Task 1.
- Produces: `Attachment` model (`id`, `attachable_type`, `attachable_id`, `disk`, `path`, `original_name`, `mime_type`, `size`), `Attachment::attachable(): MorphTo`, `Estimation::attachments(): MorphMany<Attachment>`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AttachmentTest.php`:

```php
<?php

use App\Models\Attachment;
use App\Models\Estimation;

test('attachment factory creates an attachment with a uuid primary key', function () {
    $attachment = Attachment::factory()->create();

    expect($attachment->id)->toBeString();
    expect(strlen($attachment->id))->toBe(36);
    expect($attachment->size)->toBeInt();
});

test('attachment belongs to its attachable model via morph relation', function () {
    $estimation = Estimation::factory()->create();
    $attachment = Attachment::factory()->create([
        'attachable_type' => Estimation::class,
        'attachable_id' => $estimation->id,
    ]);

    expect($attachment->attachable)->toBeInstanceOf(Estimation::class);
    expect($attachment->attachable->id)->toBe($estimation->id);
});
```

Append to `tests/Feature/EstimationTest.php`:

```php
test('estimation has many attachments and attachments are deleted when the estimation is deleted', function () {
    $estimation = Estimation::factory()->create();
    Attachment::factory()->count(2)->create([
        'attachable_type' => Estimation::class,
        'attachable_id' => $estimation->id,
    ]);

    expect($estimation->attachments)->toHaveCount(2);

    $estimation->delete();

    expect(Attachment::query()->where('attachable_id', $estimation->id)->count())->toBe(0);
});
```

Add `use App\Models\Attachment;` to the top of `tests/Feature/EstimationTest.php`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/AttachmentTest.php tests/Feature/EstimationTest.php`
Expected: FAIL — class `App\Models\Attachment` not found.

- [ ] **Step 3: Create the migration**

```bash
php artisan make:migration create_attachments_table --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('attachable_type');
            $table->uuid('attachable_id');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedInteger('size');
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $attachable_type
 * @property string $attachable_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['attachable_type', 'attachable_id', 'disk', 'path', 'original_name', 'mime_type', 'size'])]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
```

- [ ] **Step 5: Create the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Estimation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attachable_type' => Estimation::class,
            'attachable_id' => Estimation::factory(),
            'disk' => 'local',
            'path' => 'estimations/'.fake()->uuid().'/attachments/'.fake()->uuid().'.pdf',
            'original_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1000, 500000),
        ];
    }
}
```

- [ ] **Step 6: Add `attachments()` to `Estimation`, and delete them (and their files) when the estimation is deleted**

Attachments are polymorphic (`attachable_type`/`attachable_id`), so there is no DB-level foreign key to cascade the delete — it must be done in a model event. `Estimation` already has a `booted()` method from Task 1 (for number generation); add a `deleting` hook to that same method rather than declaring a second `booted()`.

In `app/Models/Estimation.php`, add the imports:

```php
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;
```

Update the existing `booted()` method to also register the `deleting` hook:

```php
    protected static function booted(): void
    {
        static::creating(function (Estimation $estimation): void {
            if (! $estimation->number) {
                $estimation->number = static::nextNumber($estimation->company_id);
            }
        });

        static::deleting(function (Estimation $estimation): void {
            foreach ($estimation->attachments as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }

            $estimation->attachments()->delete();
        });
    }
```

Add the relation:

```php
    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/AttachmentTest.php tests/Feature/EstimationTest.php`
Expected: PASS

- [ ] **Step 8: Format and check types**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=512M
```

- [ ] **Step 9: Commit**

```bash
git add database/migrations app/Models/Attachment.php app/Models/Estimation.php database/factories/AttachmentFactory.php tests/Feature/AttachmentTest.php tests/Feature/EstimationTest.php
git commit -m "feat: add generic polymorphic Attachment model"
```

---

### Task 4: `EstimationController` CRUD, requests, and routes

**Files:**
- Create: `app/Http/Requests/StoreEstimationRequest.php`, `UpdateEstimationRequest.php`
- Create: `app/Http/Controllers/EstimationController.php`
- Create: `routes/estimations.php`
- Modify: `routes/web.php` (require the new routes file)
- Test: append to `tests/Feature/EstimationTest.php`

**Interfaces:**
- Consumes: `Estimation`, `EstimationRow` (Tasks 1–2), `Customer` (already company-scoped), `CurrentCompany::resolve()`, `ScopesToCurrentCompany` trait (`authorizeCurrentCompany`, `redirectToCreateCompany`).
- Produces: routes `estimations.index`, `.create`, `.store`, `.edit`, `.update`, `.destroy`. Inertia pages `estimations/Index`, `estimations/Create`, `estimations/Edit` (built in Task 5).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/EstimationTest.php` (add `use App\Enums\EstimationStatus;`, `use App\Models\User;`, `use Illuminate\Support\Str;` at the top if not already present):

```php
test('guests are redirected to the login page when visiting estimations', function () {
    $this->get(route('estimations.index'))->assertRedirect(route('login'));
});

test('estimations index page can be rendered', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Estimation::factory()->count(3)->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('estimations.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('estimations/Index'));
});

test('estimations index only lists estimations for the current company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Estimation::factory()->count(2)->create(['company_id' => $company->id]);
    Estimation::factory()->count(3)->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('estimations.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('estimations.data', 2));
});

test('estimation create page redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('estimations.create'));

    $response->assertRedirect(route('companies.create'));
});

test('estimation can be created with rows', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.store'), [
        'customer_id' => $customer->id,
        'estimation_date' => '2026-08-14',
        'expiration_date' => '2026-09-14',
        'language' => 'en',
        'body' => 'A commercial proposal.',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 2, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('estimations.index'));

    $estimation = Estimation::query()->where('customer_id', $customer->id)->firstOrFail();
    expect($estimation->rows)->toHaveCount(1);
    expect($estimation->number)->not->toBeNull();
    expect($estimation->status)->toBe(EstimationStatus::Pending);
    expect($estimation->company_id)->toBe($company->id);
});

test('estimation customer_id must belong to the current company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $foreignCustomer = Customer::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.store'), [
        'customer_id' => $foreignCustomer->id,
        'estimation_date' => '2026-08-14',
        'expiration_date' => '2026-09-14',
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('customer_id');
});

test('estimation expiration_date must not be before estimation_date', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.store'), [
        'customer_id' => $customer->id,
        'estimation_date' => '2026-08-14',
        'expiration_date' => '2026-08-01',
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('expiration_date');
});

test('estimation edit page can be rendered', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('estimations.edit', $estimation));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('estimations/Edit')
        ->has('estimation')
        ->has('customers')
    );
});

test('viewing the edit page of an estimation from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('estimations.edit', $estimation));

    $response->assertForbidden();
});

test('updating an estimation syncs its rows and status', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Pending]);
    $keepRow = EstimationRow::factory()->create(['estimation_id' => $estimation->id, 'description' => 'Keep me']);
    $removeRow = EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('estimations.update', $estimation), [
        'estimation_date' => $estimation->estimation_date->format('Y-m-d'),
        'expiration_date' => $estimation->expiration_date->format('Y-m-d'),
        'language' => $estimation->language,
        'status' => 'accepted',
        'body' => 'Updated proposal',
        'rows' => [
            ['id' => $keepRow->id, 'description' => 'Updated description', 'quantity' => 3, 'price' => 20, 'vat_rate' => 10],
            ['description' => 'New row', 'quantity' => 1, 'price' => 30, 'vat_rate' => 4],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('estimations.index'));

    $estimation->refresh();
    expect($estimation->status)->toBe(EstimationStatus::Accepted);
    expect($estimation->body)->toBe('Updated proposal');
    expect($estimation->rows)->toHaveCount(2);
    expect(EstimationRow::query()->find($removeRow->id))->toBeNull();
    expect($keepRow->fresh()->description)->toBe('Updated description');
});

test('updating an estimation ignores a client-supplied customer_id', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $originalCustomer = Customer::factory()->create(['company_id' => $company->id]);
    $otherCustomer = Customer::factory()->create(['company_id' => $company->id]);
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'customer_id' => $originalCustomer->id]);
    $row = EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('estimations.update', $estimation), [
        'customer_id' => $otherCustomer->id,
        'estimation_date' => $estimation->estimation_date->format('Y-m-d'),
        'expiration_date' => $estimation->expiration_date->format('Y-m-d'),
        'language' => $estimation->language,
        'status' => 'pending',
        'rows' => [
            ['id' => $row->id, 'description' => $row->description, 'quantity' => $row->quantity, 'price' => $row->price, 'vat_rate' => $row->vat_rate],
        ],
    ]);

    expect($estimation->fresh()->customer_id)->toBe($originalCustomer->id);
});

test('an estimation already converted to an invoice cannot be updated', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Accepted, 'invoice_id' => $invoice->id, 'body' => 'Original']);
    $row = EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('estimations.update', $estimation), [
        'estimation_date' => $estimation->estimation_date->format('Y-m-d'),
        'expiration_date' => $estimation->expiration_date->format('Y-m-d'),
        'language' => $estimation->language,
        'status' => 'accepted',
        'body' => 'Hacked',
        'rows' => [
            ['id' => $row->id, 'description' => $row->description, 'quantity' => $row->quantity, 'price' => $row->price, 'vat_rate' => $row->vat_rate],
        ],
    ]);

    expect($estimation->fresh()->body)->toBe('Original');
});

test('estimation can be deleted when pending', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Pending]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('estimations.destroy', $estimation));

    $response->assertRedirect(route('estimations.index'));
    expect(Estimation::query()->find($estimation->id))->toBeNull();
});

test('an accepted estimation cannot be deleted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Accepted]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('estimations.destroy', $estimation));

    expect(Estimation::query()->find($estimation->id))->not->toBeNull();
});

test('a converted estimation cannot be deleted even if rejected', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Rejected, 'invoice_id' => $invoice->id]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('estimations.destroy', $estimation));

    expect(Estimation::query()->find($estimation->id))->not->toBeNull();
});
```

Add `use App\Models\Invoice;` and `use App\Models\EstimationRow;` to the top of `tests/Feature/EstimationTest.php` if not already present.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/EstimationTest.php`
Expected: FAIL — route `estimations.index` not defined.

- [ ] **Step 3: Create the form requests**

`app/Http/Requests/StoreEstimationRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEstimationRequest extends FormRequest
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
            'customer_id' => [
                'required', 'uuid',
                Rule::exists('customers', 'id')->where('company_id', CurrentCompany::resolve()?->id),
            ],
            'estimation_date' => ['required', 'date'],
            'expiration_date' => ['required', 'date', 'after_or_equal:estimation_date'],
            'language' => ['required', 'string', Rule::in(['it', 'en', 'es'])],
            'body' => ['nullable', 'string'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'rows.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

`app/Http/Requests/UpdateEstimationRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\EstimationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEstimationRequest extends FormRequest
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
            'estimation_date' => ['required', 'date'],
            'expiration_date' => ['required', 'date', 'after_or_equal:estimation_date'],
            'language' => ['required', 'string', Rule::in(['it', 'en', 'es'])],
            'status' => ['required', Rule::enum(EstimationStatus::class)],
            'body' => ['nullable', 'string'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.id' => ['nullable', 'uuid', 'exists:estimation_rows,id'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'rows.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

Note: `customer_id` is intentionally never read from the update request — it stays whatever it already was.

- [ ] **Step 4: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\EstimationStatus;
use App\Http\Controllers\Concerns\ScopesToCurrentCompany;
use App\Http\Requests\StoreEstimationRequest;
use App\Http\Requests\UpdateEstimationRequest;
use App\Models\Customer;
use App\Models\Estimation;
use App\Models\EstimationRow;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EstimationController extends Controller
{
    use ScopesToCurrentCompany;

    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();
        $currentCompanyId = CurrentCompany::resolve()?->id;

        $estimations = Estimation::query()
            ->with(['customer', 'rows'])
            ->where('company_id', $currentCompanyId)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($query) => $query->where('name', 'like', "%{$search}%"));
            }))
            ->orderByDesc('number')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('estimations/Index', [
            'estimations' => $estimations,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $currentCompany = CurrentCompany::resolve();

        if ($currentCompany === null) {
            return $this->redirectToCreateCompany();
        }

        $duplicateId = is_string($id = $request->query('duplicate')) ? trim($id) : '';

        $source = $duplicateId !== ''
            ? Estimation::query()->with('rows')->find($duplicateId)
            : null;

        return Inertia::render('estimations/Create', [
            'customers' => Customer::query()->where('company_id', $currentCompany->id)->orderBy('name')->get(['id', 'name']),
            'nextNumber' => Estimation::nextNumber($currentCompany->id),
            'duplicate' => $source ? [
                ...($source->company_id === $currentCompany->id ? ['customer_id' => $source->customer_id] : []),
                'body' => $source->body,
                'language' => $source->language,
                'rows' => $source->rows->map(fn (EstimationRow $row): array => [
                    'description' => $row->description,
                    'quantity' => $row->quantity,
                    'price' => $row->price,
                    'vat_rate' => $row->vat_rate,
                    'note' => $row->note,
                ])->all(),
            ] : null,
        ]);
    }

    public function store(StoreEstimationRequest $request): RedirectResponse
    {
        $currentCompany = CurrentCompany::resolve();

        if ($currentCompany === null) {
            return $this->redirectToCreateCompany();
        }

        DB::transaction(function () use ($request, $currentCompany) {
            $estimation = Estimation::query()->create([
                ...$request->safe()->except('rows'),
                'company_id' => $currentCompany->id,
            ]);

            $estimation->rows()->createMany($request->safe()->input('rows'));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Estimation created.')]);

        return to_route('estimations.index');
    }

    public function edit(Estimation $estimation): Response
    {
        $this->authorizeCurrentCompany($estimation);

        return Inertia::render('estimations/Edit', [
            'estimation' => $estimation->load(['rows', 'attachments']),
            'customers' => Customer::query()->where('company_id', $estimation->company_id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateEstimationRequest $request, Estimation $estimation): RedirectResponse
    {
        $this->authorizeCurrentCompany($estimation);

        if ($estimation->invoice_id !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This estimation was already converted to an invoice and can no longer be edited.')]);

            return to_route('estimations.edit', $estimation);
        }

        DB::transaction(function () use ($request, $estimation) {
            $estimation->update($request->safe()->except('rows'));

            $rows = collect($request->safe()->input('rows'));
            $keepIds = $rows->pluck('id')->filter()->all();

            $estimation->rows()->whereNotIn('id', $keepIds)->delete();

            foreach ($rows as $row) {
                $attributes = collect($row)->except('id')->all();

                if ($rowId = $row['id'] ?? null) {
                    $estimation->rows()->whereKey($rowId)->update($attributes);
                } else {
                    $estimation->rows()->create($attributes);
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Estimation updated.')]);

        return to_route('estimations.index');
    }

    public function destroy(Estimation $estimation): RedirectResponse
    {
        $this->authorizeCurrentCompany($estimation);

        if ($estimation->status === EstimationStatus::Accepted || $estimation->invoice_id !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('An accepted or converted estimation cannot be deleted.')]);

            return to_route('estimations.index');
        }

        $estimation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Estimation deleted.')]);

        return to_route('estimations.index');
    }
}
```

- [ ] **Step 5: Create the routes file**

`routes/estimations.php`:

```php
<?php

use App\Http\Controllers\EstimationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('estimations', EstimationController::class)->except('show');
});
```

In `routes/web.php`, add alongside the other `require` lines:

```php
require __DIR__.'/estimations.php';
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/EstimationTest.php`
Expected: PASS

- [ ] **Step 7: Format and check types**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=512M
```

- [ ] **Step 8: Regenerate Wayfinder helpers**

```bash
php artisan wayfinder:generate
```

Expected: `resources/js/actions/App/Http/Controllers/EstimationController.ts` and `resources/js/routes/estimations/index.ts` are created.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/StoreEstimationRequest.php app/Http/Requests/UpdateEstimationRequest.php app/Http/Controllers/EstimationController.php routes/estimations.php routes/web.php tests/Feature/EstimationTest.php resources/js/actions/App/Http/Controllers/EstimationController.ts resources/js/routes/estimations
git commit -m "feat: add estimations CRUD controller, requests, and routes"
```

---

### Task 5: Basic frontend CRUD (Index, Create, Edit) and navigation

**Files:**
- Create: `resources/js/pages/estimations/Index.vue`, `Create.vue`, `Edit.vue`
- Modify: `resources/js/components/AppSidebar.vue`
- Modify: `resources/js/lang/en.ts`, `it.ts`, `es.ts`

**Interfaces:**
- Consumes: `EstimationController` (wayfinder), `resources/js/routes/estimations` (`index`, `create`, `edit`), `ProductPicker.vue`/`PickedProduct` (already exists), `addDurationToDate` (already exists).
- Produces: working CRUD UI for estimations without markdown preview, attachments, PDF/ZIP, or conversion — those are added in later tasks. `body` is a plain textarea for now (Task 8 upgrades it).

- [ ] **Step 1: Add translation keys**

In `resources/js/lang/en.ts`, add `estimations: 'Estimates',` to the `nav` object (after `products:`), and a new top-level `estimations` block (after the `invoices` block, before `productPicker`):

```ts
    estimations: {
        status: {
            pending: 'Pending',
            accepted: 'Accepted',
            rejected: 'Rejected',
        },
        index: {
            title: 'Estimates',
            description: 'Manage your estimates',
            newButton: 'New estimate',
            searchPlaceholder: 'Search by number or customer...',
            columns: {
                number: 'Number',
                date: 'Date',
                customer: 'Customer',
                status: 'Status',
                total: 'Total',
            },
            empty: 'No estimates found.',
        },
        create: {
            title: 'New estimate',
            description: 'Create a new estimate',
            number: 'Number',
            date: 'Date',
            expirationDate: 'Expiration date',
            customer: 'Customer',
            selectCustomer: 'Select a customer',
            language: 'Language',
            selectLanguage: 'Select a language',
            body: 'Proposal',
            bodyPlaceholder: 'Optional commercial proposal (Markdown supported)',
            rows: 'Rows',
            rowDescription: 'Description',
            rowQuantity: 'Quantity',
            rowPrice: 'Price',
            rowVat: 'VAT (%)',
            rowVatPlaceholder: 'VAT %',
            rowNote: 'Note',
            total: 'Total: {amount}',
            confirmRemoveRow: 'Remove this row?',
        },
        edit: {
            title: 'Edit estimate',
            description: 'Update estimate {number}',
            status: 'Status',
            confirmDelete: 'Delete this estimate? This cannot be undone.',
            deleteButton: 'Delete estimate',
        },
    },
```

Mirror the same structure with Italian copy in `resources/js/lang/it.ts` (add `estimations: 'Preventivi',` to `nav`):

```ts
    estimations: {
        status: {
            pending: 'In attesa',
            accepted: 'Accettato',
            rejected: 'Rifiutato',
        },
        index: {
            title: 'Preventivi',
            description: 'Gestisci i tuoi preventivi',
            newButton: 'Nuovo preventivo',
            searchPlaceholder: 'Cerca per numero o cliente...',
            columns: {
                number: 'Numero',
                date: 'Data',
                customer: 'Cliente',
                status: 'Stato',
                total: 'Totale',
            },
            empty: 'Nessun preventivo trovato.',
        },
        create: {
            title: 'Nuovo preventivo',
            description: 'Crea un nuovo preventivo',
            number: 'Numero',
            date: 'Data',
            expirationDate: 'Data di scadenza',
            customer: 'Cliente',
            selectCustomer: 'Seleziona un cliente',
            language: 'Lingua',
            selectLanguage: 'Seleziona una lingua',
            body: 'Proposta',
            bodyPlaceholder: 'Proposta commerciale opzionale (supporta Markdown)',
            rows: 'Righe',
            rowDescription: 'Descrizione',
            rowQuantity: 'Quantità',
            rowPrice: 'Prezzo',
            rowVat: 'IVA (%)',
            rowVatPlaceholder: 'IVA %',
            rowNote: 'Nota',
            total: 'Totale: {amount}',
            confirmRemoveRow: 'Rimuovere questa riga?',
        },
        edit: {
            title: 'Modifica preventivo',
            description: 'Aggiorna il preventivo {number}',
            status: 'Stato',
            confirmDelete: 'Eliminare questo preventivo? L\'azione non può essere annullata.',
            deleteButton: 'Elimina preventivo',
        },
    },
```

And Spanish copy in `resources/js/lang/es.ts` (add `estimations: 'Presupuestos',` to `nav`):

```ts
    estimations: {
        status: {
            pending: 'Pendiente',
            accepted: 'Aceptado',
            rejected: 'Rechazado',
        },
        index: {
            title: 'Presupuestos',
            description: 'Gestiona tus presupuestos',
            newButton: 'Nuevo presupuesto',
            searchPlaceholder: 'Buscar por número o cliente...',
            columns: {
                number: 'Número',
                date: 'Fecha',
                customer: 'Cliente',
                status: 'Estado',
                total: 'Total',
            },
            empty: 'No se encontraron presupuestos.',
        },
        create: {
            title: 'Nuevo presupuesto',
            description: 'Crea un nuevo presupuesto',
            number: 'Número',
            date: 'Fecha',
            expirationDate: 'Fecha de vencimiento',
            customer: 'Cliente',
            selectCustomer: 'Selecciona un cliente',
            language: 'Idioma',
            selectLanguage: 'Selecciona un idioma',
            body: 'Propuesta',
            bodyPlaceholder: 'Propuesta comercial opcional (admite Markdown)',
            rows: 'Líneas',
            rowDescription: 'Descripción',
            rowQuantity: 'Cantidad',
            rowPrice: 'Precio',
            rowVat: 'IGIC (%)',
            rowVatPlaceholder: 'IGIC %',
            rowNote: 'Nota',
            total: 'Total: {amount}',
            confirmRemoveRow: '¿Eliminar esta línea?',
        },
        edit: {
            title: 'Editar presupuesto',
            description: 'Actualizar el presupuesto {number}',
            status: 'Estado',
            confirmDelete: '¿Eliminar este presupuesto? Esta acción no se puede deshacer.',
            deleteButton: 'Eliminar presupuesto',
        },
    },
```

- [ ] **Step 2: Add the sidebar nav entry**

In `resources/js/components/AppSidebar.vue`, add the icon import and route import:

```ts
import { FileSignature, ... } from '@lucide/vue'; // add FileSignature to the existing import list, alphabetically
import { index as estimationsIndex } from '@/routes/estimations';
```

Add a nav item to `mainNavItems`, after the `invoices` entry:

```ts
    {
        title: t('nav.estimations'),
        href: estimationsIndex().url,
        icon: FileSignature,
    },
```

- [ ] **Step 3: Create `resources/js/pages/estimations/Create.vue`**

```vue
<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import EstimationController from '@/actions/App/Http/Controllers/EstimationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/estimations';
import type { BreadcrumbItem } from '@/types';

type Customer = {
    id: string;
    name: string;
};

type EstimationRowForm = {
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
    note: string | null;
};

const props = defineProps<{
    customers: Customer[];
    nextNumber: string;
    duplicate: {
        customer_id?: string;
        body: string | null;
        language: string;
        rows: EstimationRowForm[];
    } | null;
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('estimations.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const form = useForm({
    number: props.nextNumber,
    customer_id: props.duplicate?.customer_id ?? '',
    estimation_date: new Date().toISOString().slice(0, 10),
    expiration_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000)
        .toISOString()
        .slice(0, 10),
    language: props.duplicate?.language ?? 'es',
    body: props.duplicate?.body ?? '',
    rows: props.duplicate?.rows ?? [
        { description: '', quantity: 1, price: 0, vat_rate: 0, note: null },
    ],
});

function addRow(): void {
    form.rows.push({
        description: '',
        quantity: 1,
        price: 0,
        vat_rate: 0,
        note: null,
    });
}

function removeRow(index: number): void {
    if (!confirm(t('estimations.create.confirmRemoveRow'))) {
        return;
    }

    form.rows.splice(index, 1);
}

const total = computed(() =>
    form.rows.reduce((sum, row) => {
        const lineTotal = row.price * row.quantity;

        return sum + lineTotal + (lineTotal * row.vat_rate) / 100;
    }, 0),
);

function submit(): void {
    form.post(EstimationController.store().url);
}
</script>

<template>
    <Head :title="t('estimations.create.title')" />

    <div class="flex max-w-5xl flex-col space-y-6">
        <Heading
            :title="t('estimations.create.title')"
            :description="t('estimations.create.description')"
        />

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-3 gap-4">
                <div class="grid gap-2">
                    <Label for="number">{{
                        t('estimations.create.number')
                    }}</Label>
                    <Input
                        id="number"
                        v-model="form.number"
                        placeholder="2026-0001"
                    />
                    <InputError :message="form.errors.number" />
                </div>
                <div class="grid gap-2">
                    <Label for="estimation_date">{{
                        t('estimations.create.date')
                    }}</Label>
                    <Input
                        id="estimation_date"
                        v-model="form.estimation_date"
                        type="date"
                    />
                    <InputError :message="form.errors.estimation_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="expiration_date">{{
                        t('estimations.create.expirationDate')
                    }}</Label>
                    <Input
                        id="expiration_date"
                        v-model="form.expiration_date"
                        type="date"
                    />
                    <InputError :message="form.errors.expiration_date" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="customer_id">{{
                        t('estimations.create.customer')
                    }}</Label>
                    <Select v-model="form.customer_id">
                        <SelectTrigger id="customer_id" class="w-full">
                            <SelectValue
                                :placeholder="
                                    t('estimations.create.selectCustomer')
                                "
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="customer in customers"
                                :key="customer.id"
                                :value="customer.id"
                            >
                                {{ customer.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.customer_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="language">{{
                        t('estimations.create.language')
                    }}</Label>
                    <Select v-model="form.language">
                        <SelectTrigger id="language" class="w-full">
                            <SelectValue
                                :placeholder="
                                    t('estimations.create.selectLanguage')
                                "
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="it">Italiano</SelectItem>
                            <SelectItem value="en">English</SelectItem>
                            <SelectItem value="es">Español</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.language" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="body">{{ t('estimations.create.body') }}</Label>
                <textarea
                    id="body"
                    v-model="form.body"
                    rows="8"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                    :placeholder="t('estimations.create.bodyPlaceholder')"
                />
                <InputError :message="form.errors.body" />
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <Label>{{ t('estimations.create.rows') }}</Label>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="addRow"
                    >
                        <Plus />
                        {{ t('common.actions.addRow') }}
                    </Button>
                </div>
                <InputError :message="form.errors.rows" />

                <div
                    class="grid grid-cols-[1fr_6rem_8rem_6rem_1fr_2.5rem] gap-2 text-sm text-muted-foreground"
                >
                    <span>{{ t('estimations.create.rowDescription') }}</span>
                    <span>{{ t('estimations.create.rowQuantity') }}</span>
                    <span>{{ t('estimations.create.rowPrice') }}</span>
                    <span>{{ t('estimations.create.rowVat') }}</span>
                    <span>{{ t('estimations.create.rowNote') }}</span>
                    <span></span>
                </div>

                <div
                    v-for="(row, i) in form.rows"
                    :key="i"
                    class="grid grid-cols-[1fr_6rem_8rem_6rem_1fr_2.5rem] items-start gap-2"
                >
                    <div class="grid gap-1">
                        <Input
                            v-model="row.description"
                            :placeholder="t('estimations.create.rowDescription')"
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
                            :placeholder="t('estimations.create.rowQuantity')"
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
                            :placeholder="t('estimations.create.rowPrice')"
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
                            :placeholder="
                                t('estimations.create.rowVatPlaceholder')
                            "
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.vat_rate`]"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            :model-value="row.note ?? ''"
                            :placeholder="t('estimations.create.rowNote')"
                            @update:model-value="
                                (value) => (row.note = String(value) || null)
                            "
                        />
                        <InputError :message="form.errors[`rows.${i}.note`]" />
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

                <p class="text-right text-sm text-muted-foreground">
                    {{
                        t('estimations.create.total', {
                            amount: total.toFixed(2),
                        })
                    }}
                </p>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button :disabled="form.processing" type="submit">{{
                    t('common.actions.save')
                }}</Button>
                <Link
                    :href="index()"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    {{ t('common.actions.cancel') }}
                </Link>
            </div>
        </form>
    </div>
</template>
```

- [ ] **Step 4: Create `resources/js/pages/estimations/Edit.vue`**

```vue
<script setup lang="ts">
import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import EstimationController from '@/actions/App/Http/Controllers/EstimationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/estimations';
import type { BreadcrumbItem } from '@/types';

type Customer = {
    id: string;
    name: string;
};

type EstimationStatus = 'pending' | 'accepted' | 'rejected';

type EstimationRow = {
    id: string;
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
    note: string | null;
};

type Estimation = {
    id: string;
    number: string;
    customer_id: string;
    estimation_date: string;
    expiration_date: string;
    language: string;
    body: string | null;
    status: EstimationStatus;
    invoice_id: string | null;
    rows: EstimationRow[];
};

type EstimationRowForm = {
    id?: string;
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
    note: string | null;
};

const props = defineProps<{
    estimation: Estimation;
    customers: Customer[];
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('estimations.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const form = useForm({
    estimation_date: props.estimation.estimation_date,
    expiration_date: props.estimation.expiration_date,
    language: props.estimation.language,
    status: props.estimation.status,
    body: props.estimation.body ?? '',
    rows: props.estimation.rows.map((row) => ({
        id: row.id,
        description: row.description,
        quantity: row.quantity,
        price: row.price,
        vat_rate: row.vat_rate,
        note: row.note,
    })) as EstimationRowForm[],
});

const isConverted = computed(() => props.estimation.invoice_id !== null);

function addRow(): void {
    form.rows.push({
        description: '',
        quantity: 1,
        price: 0,
        vat_rate: 0,
        note: null,
    });
}

function removeRow(index: number): void {
    if (!confirm(t('estimations.create.confirmRemoveRow'))) {
        return;
    }

    form.rows.splice(index, 1);
}

const total = computed(() =>
    form.rows.reduce((sum, row) => {
        const lineTotal = row.price * row.quantity;

        return sum + lineTotal + (lineTotal * row.vat_rate) / 100;
    }, 0),
);

function submit(): void {
    form.put(EstimationController.update(props.estimation.id).url);
}

function onDelete(): void {
    if (confirm(t('estimations.edit.confirmDelete'))) {
        router.delete(EstimationController.destroy(props.estimation.id).url);
    }
}
</script>

<template>
    <Head :title="t('estimations.edit.title')" />

    <div class="flex max-w-5xl flex-col space-y-6">
        <Heading
            :title="t('estimations.edit.title')"
            :description="
                t('estimations.edit.description', { number: estimation.number })
            "
        />

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-3 gap-4">
                <div class="grid gap-2">
                    <Label for="estimation_date">{{
                        t('estimations.create.date')
                    }}</Label>
                    <Input
                        id="estimation_date"
                        v-model="form.estimation_date"
                        type="date"
                    />
                    <InputError :message="form.errors.estimation_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="expiration_date">{{
                        t('estimations.create.expirationDate')
                    }}</Label>
                    <Input
                        id="expiration_date"
                        v-model="form.expiration_date"
                        type="date"
                    />
                    <InputError :message="form.errors.expiration_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="status">{{
                        t('estimations.edit.status')
                    }}</Label>
                    <Select v-model="form.status">
                        <SelectTrigger id="status" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="pending">{{
                                t('estimations.status.pending')
                            }}</SelectItem>
                            <SelectItem value="accepted">{{
                                t('estimations.status.accepted')
                            }}</SelectItem>
                            <SelectItem value="rejected">{{
                                t('estimations.status.rejected')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.status" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="language">{{
                    t('estimations.create.language')
                }}</Label>
                <Select v-model="form.language">
                    <SelectTrigger id="language" class="w-full max-w-xs">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="it">Italiano</SelectItem>
                        <SelectItem value="en">English</SelectItem>
                        <SelectItem value="es">Español</SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.language" />
            </div>

            <div class="grid gap-2">
                <Label for="body">{{ t('estimations.create.body') }}</Label>
                <textarea
                    id="body"
                    v-model="form.body"
                    rows="8"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                    :placeholder="t('estimations.create.bodyPlaceholder')"
                />
                <InputError :message="form.errors.body" />
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <Label>{{ t('estimations.create.rows') }}</Label>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="addRow"
                    >
                        <Plus />
                        {{ t('common.actions.addRow') }}
                    </Button>
                </div>
                <InputError :message="form.errors.rows" />

                <div
                    class="grid grid-cols-[1fr_6rem_8rem_6rem_1fr_2.5rem] gap-2 text-sm text-muted-foreground"
                >
                    <span>{{ t('estimations.create.rowDescription') }}</span>
                    <span>{{ t('estimations.create.rowQuantity') }}</span>
                    <span>{{ t('estimations.create.rowPrice') }}</span>
                    <span>{{ t('estimations.create.rowVat') }}</span>
                    <span>{{ t('estimations.create.rowNote') }}</span>
                    <span></span>
                </div>

                <div
                    v-for="(row, i) in form.rows"
                    :key="row.id ?? `new-${i}`"
                    class="grid grid-cols-[1fr_6rem_8rem_6rem_1fr_2.5rem] items-start gap-2"
                >
                    <div class="grid gap-1">
                        <Input
                            v-model="row.description"
                            :placeholder="t('estimations.create.rowDescription')"
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
                            :placeholder="t('estimations.create.rowQuantity')"
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
                            :placeholder="t('estimations.create.rowPrice')"
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
                            :placeholder="
                                t('estimations.create.rowVatPlaceholder')
                            "
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.vat_rate`]"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            :model-value="row.note ?? ''"
                            :placeholder="t('estimations.create.rowNote')"
                            @update:model-value="
                                (value) => (row.note = String(value) || null)
                            "
                        />
                        <InputError :message="form.errors[`rows.${i}.note`]" />
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

                <p class="text-right text-sm text-muted-foreground">
                    {{
                        t('estimations.create.total', {
                            amount: total.toFixed(2),
                        })
                    }}
                </p>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button
                    :disabled="form.processing || isConverted"
                    type="submit"
                    >{{ t('common.actions.save') }}</Button
                >
                <Link
                    :href="index()"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    {{ t('common.actions.cancel') }}
                </Link>
            </div>
        </form>

        <div class="border-t pt-6">
            <Button
                variant="destructive"
                type="button"
                :disabled="isConverted"
                @click="onDelete"
            >
                {{ t('estimations.edit.deleteButton') }}
            </Button>
        </div>
    </div>
</template>
```

Note: the customer picker is intentionally not shown here (customer is immutable after creation — no `customer_id` field in this form at all).

- [ ] **Step 5: Create `resources/js/pages/estimations/Index.vue`**

```vue
<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, edit, index } from '@/routes/estimations';
import type { BreadcrumbItem } from '@/types';

type EstimationStatus = 'pending' | 'accepted' | 'rejected';

type Estimation = {
    id: string;
    number: string;
    estimation_date: string;
    status: EstimationStatus;
    total: string | number;
    customer: { id: string; name: string } | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('estimations.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const props = defineProps<{
    estimations: {
        data: Estimation[];
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

function formatTotal(total: string | number): string {
    return Number(total).toFixed(2);
}

function formatDate(date: string): string {
    const [year, month, day] = date.split('-');

    return `${day}/${month}/${year}`;
}

function statusBadgeVariant(
    status: EstimationStatus,
): 'default' | 'secondary' | 'destructive' {
    if (status === 'accepted') {
        return 'default';
    }

    if (status === 'rejected') {
        return 'destructive';
    }

    return 'secondary';
}
</script>

<template>
    <Head :title="t('estimations.index.title')" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                :title="t('estimations.index.title')"
                :description="t('estimations.index.description')"
            />
            <Button as-child>
                <Link :href="create()">
                    <Plus />
                    {{ t('estimations.index.newButton') }}
                </Link>
            </Button>
        </div>

        <form class="max-w-sm" @submit.prevent="onSearch">
            <Input
                v-model="search"
                :placeholder="t('estimations.index.searchPlaceholder')"
            />
        </form>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">
                            {{ t('estimations.index.columns.number') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('estimations.index.columns.date') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('estimations.index.columns.customer') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('estimations.index.columns.status') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('estimations.index.columns.total') }}
                        </th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="estimation in estimations.data"
                        :key="estimation.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">{{ estimation.number }}</td>
                        <td class="px-4 py-2">
                            {{ formatDate(estimation.estimation_date) }}
                        </td>
                        <td class="px-4 py-2">
                            {{ estimation.customer?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-2">
                            <Badge :variant="statusBadgeVariant(estimation.status)">
                                {{ t(`estimations.status.${estimation.status}`) }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2">
                            {{ formatTotal(estimation.total) }}
                        </td>
                        <td class="space-x-1 px-4 py-2 text-right">
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :title="t('common.actions.edit')"
                            >
                                <Link :href="edit(estimation.id)">
                                    <Pencil />
                                </Link>
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="estimations.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            {{ t('estimations.index.empty') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="estimations.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in estimations.links"
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

Task 14 adds the preview/PDF/ZIP/duplicate action icons to this table's actions column.

- [ ] **Step 6: Build and manually verify**

```bash
npm run build
```

Log in, open the "Preventivi" nav entry, create an estimate with two rows, verify it lists, edit it (change status, add/remove a row, save), verify deletion works when pending and is blocked once you manually set `status` to `accepted` and try again.

- [ ] **Step 7: Type-check and lint**

```bash
npm run types:check
npm run lint:check
npm run format:check
```

- [ ] **Step 8: Commit**

```bash
git add resources/js/pages/estimations resources/js/components/AppSidebar.vue resources/js/lang
git commit -m "feat: add basic estimations CRUD UI"
```

---

### Task 6: Markdown renderer and live preview endpoint

**Files:**
- Create: `app/Support/MarkdownRenderer.php`
- Modify: `app/Http/Controllers/EstimationController.php` (add `markdownPreview`)
- Modify: `routes/estimations.php`
- Test: create `tests/Feature/MarkdownPreviewTest.php`

**Interfaces:**
- Produces: `App\Support\MarkdownRenderer::toHtml(?string $markdown): string`, route `estimations.markdown-preview` (`POST`, no `{estimation}` binding), returning `{ html: string }`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/MarkdownPreviewTest.php`:

```php
<?php

use App\Models\User;

test('guests are redirected to the login page when requesting a markdown preview', function () {
    $this->post(route('estimations.markdown-preview'), ['body' => '# Hi'])
        ->assertRedirect(route('login'));
});

test('markdown preview renders the given body to html', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('estimations.markdown-preview'), [
        'body' => "# Hello\n\nSome **bold** text.",
    ]);

    $response->assertOk();
    expect($response->json('html'))->toContain('<h1>Hello</h1>');
    expect($response->json('html'))->toContain('<strong>bold</strong>');
});

test('markdown preview returns an empty string for an empty body', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('estimations.markdown-preview'), ['body' => '']);

    $response->assertOk();
    expect($response->json('html'))->toBe('');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/MarkdownPreviewTest.php`
Expected: FAIL — route `estimations.markdown-preview` not defined.

- [ ] **Step 3: Create `MarkdownRenderer`**

```php
<?php

namespace App\Support;

use League\CommonMark\CommonMarkConverter;

class MarkdownRenderer
{
    public static function toHtml(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        return (string) (new CommonMarkConverter())->convertToHtml($markdown);
    }
}
```

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/EstimationController.php`, add the imports and method:

```php
use App\Support\MarkdownRenderer;
use Illuminate\Http\JsonResponse;
```

```php
    public function markdownPreview(Request $request): JsonResponse
    {
        return response()->json([
            'html' => MarkdownRenderer::toHtml($request->string('body')->toString()),
        ]);
    }
```

- [ ] **Step 5: Add the route**

In `routes/estimations.php`, inside the existing `Route::middleware(['auth', 'verified'])->group(...)` closure, add this line **before** `Route::resource(...)`:

```php
    Route::post('estimations/markdown-preview', [EstimationController::class, 'markdownPreview'])->name('estimations.markdown-preview');
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/MarkdownPreviewTest.php`
Expected: PASS

- [ ] **Step 7: Format, check types, regenerate Wayfinder**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=512M
php artisan wayfinder:generate
```

- [ ] **Step 8: Commit**

```bash
git add app/Support/MarkdownRenderer.php app/Http/Controllers/EstimationController.php routes/estimations.php tests/Feature/MarkdownPreviewTest.php resources/js/actions/App/Http/Controllers/EstimationController.ts resources/js/routes/estimations
git commit -m "feat: add markdown renderer and live preview endpoint"
```

---

### Task 7: PDF template, preview, and download

**Files:**
- Create: `resources/views/estimations/template.blade.php`
- Create: `resources/lang/en/estimation.php`, `it/estimation.php`, `es/estimation.php`
- Modify: `app/Http/Controllers/EstimationController.php` (add `preview`, `pdf`)
- Modify: `routes/estimations.php`
- Test: create `tests/Feature/EstimationTemplateTest.php`

**Interfaces:**
- Consumes: `MarkdownRenderer` (Task 6), `Estimation` fully loaded (`customer.country`, `company.country`, `rows`).
- Produces: routes `estimations.preview` (HTML view), `estimations.pdf` (download).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/EstimationTemplateTest.php`:

```php
<?php

use App\Enums\EstimationStatus;
use App\Models\Estimation;
use App\Models\EstimationRow;
use App\Models\User;
use Illuminate\Support\Facades\App;

function renderEstimationTemplate(Estimation $estimation): string
{
    App::setLocale($estimation->language);

    return view('estimations.template', [
        'estimation' => $estimation->load(['customer.country', 'company.country', 'rows']),
        'bodyHtml' => \App\Support\MarkdownRenderer::toHtml($estimation->body),
    ])->render();
}

test('template renders company data, customer data, rows, and the rendered proposal body', function () {
    $estimation = Estimation::factory()->create(['language' => 'en', 'body' => '**Important** proposal']);
    EstimationRow::factory()->create([
        'estimation_id' => $estimation->id,
        'description' => 'Consulting work',
        'price' => 100,
        'vat_rate' => 22,
    ]);

    $html = renderEstimationTemplate($estimation);

    expect($html)->toContain('Estimate');
    expect($html)->toContain($estimation->number);
    expect($html)->toContain(e($estimation->company->name));
    expect($html)->toContain(e($estimation->customer->name));
    expect($html)->toContain('Consulting work');
    expect($html)->toContain('<strong>Important</strong> proposal');
});

test('template labels switch per estimation language', function () {
    $it = Estimation::factory()->create(['language' => 'it']);
    $es = Estimation::factory()->create(['language' => 'es']);
    EstimationRow::factory()->create(['estimation_id' => $it->id]);
    EstimationRow::factory()->create(['estimation_id' => $es->id]);

    expect(renderEstimationTemplate($it))->toContain('Preventivo');
    expect(renderEstimationTemplate($es))->toContain('Presupuesto');
});

test('template omits the proposal section when the body is empty', function () {
    $estimation = Estimation::factory()->create(['language' => 'en', 'body' => null]);
    EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $html = renderEstimationTemplate($estimation);

    expect($html)->not->toContain('id="proposal"');
});

test('guests are redirected to the login page when previewing an estimation', function () {
    $estimation = Estimation::factory()->create();

    $this->get(route('estimations.preview', $estimation))->assertRedirect(route('login'));
});

test('estimation preview renders as html', function () {
    $user = User::factory()->create();
    $estimation = Estimation::factory()->create(['language' => 'en']);
    EstimationRow::factory()->create(['estimation_id' => $estimation->id, 'description' => 'Design work']);

    $response = $this->actingAs($user)->get(route('estimations.preview', $estimation));

    $response->assertOk();
    $response->assertSee($estimation->number);
    $response->assertSee('Design work');
});

test('estimation pdf downloads as a pdf file', function () {
    $user = User::factory()->create();
    $estimation = Estimation::factory()->create();
    EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $response = $this->actingAs($user)->get(route('estimations.pdf', $estimation));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain($estimation->number);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/EstimationTemplateTest.php`
Expected: FAIL — view `estimations.template` not found.

- [ ] **Step 3: Create the translation files**

`resources/lang/en/estimation.php`:

```php
<?php

return [
    'title' => 'Estimate',
    'number' => 'Number',
    'date' => 'Date',
    'expiration_date' => 'Valid until',
    'customer' => 'Customer',
    'description' => 'Description',
    'quantity' => 'Quantity',
    'price' => 'Price',
    'vat' => 'VAT',
    'subtotal' => 'Subtotal',
    'total' => 'Total',
    'tax_id' => 'Tax ID',
    'proposal' => 'Proposal',
    'status_pending' => 'Pending',
    'status_accepted' => 'Accepted',
    'status_rejected' => 'Rejected',
];
```

`resources/lang/it/estimation.php`:

```php
<?php

return [
    'title' => 'Preventivo',
    'number' => 'Numero',
    'date' => 'Data',
    'expiration_date' => 'Valido fino al',
    'customer' => 'Cliente',
    'description' => 'Descrizione',
    'quantity' => 'Quantità',
    'price' => 'Prezzo',
    'vat' => 'IVA',
    'subtotal' => 'Subtotale',
    'total' => 'Totale',
    'tax_id' => 'P.IVA',
    'proposal' => 'Proposta',
    'status_pending' => 'In attesa',
    'status_accepted' => 'Accettato',
    'status_rejected' => 'Rifiutato',
];
```

`resources/lang/es/estimation.php`:

```php
<?php

return [
    'title' => 'Presupuesto',
    'number' => 'Número',
    'date' => 'Fecha',
    'expiration_date' => 'Válido hasta',
    'customer' => 'Cliente',
    'description' => 'Descripción',
    'quantity' => 'Cantidad',
    'price' => 'Precio',
    'vat' => 'IVA',
    'subtotal' => 'Subtotal',
    'total' => 'Total',
    'tax_id' => 'NIF',
    'proposal' => 'Propuesta',
    'status_pending' => 'Pendiente',
    'status_accepted' => 'Aceptado',
    'status_rejected' => 'Rechazado',
];
```

- [ ] **Step 4: Create the template**

`resources/views/estimations/template.blade.php`:

```blade
@php
    $logoPath = $estimation->company->logo ? public_path($estimation->company->logo) : null;
    $logoData = $logoPath && file_exists($logoPath)
        ? 'data:' . mime_content_type($logoPath) . ';base64,' . base64_encode(file_get_contents($logoPath))
        : null;

    $statusLabel = match ($estimation->status) {
        \App\Enums\EstimationStatus::Accepted => __('estimation.status_accepted'),
        \App\Enums\EstimationStatus::Rejected => __('estimation.status_rejected'),
        \App\Enums\EstimationStatus::Pending => __('estimation.status_pending'),
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $estimation->language }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('estimation.title') }} {{ $estimation->number }}</title>
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
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-accepted { background: #dcfce7; color: #166534; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
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
        .proposal { margin-top: 40px; padding-top: 12px; border-top: 1px solid #e5e7eb; }
        .proposal h2 { font-size: 11px; text-transform: uppercase; color: #6b7280; margin: 0 0 4px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                @if($logoData)
                    <img class="logo" src="{{ $logoData }}" alt="{{ $estimation->company->name }}">
                @endif
                <div class="company">
                    <strong>{{ $estimation->company->name }}</strong><br>
                    {{ $estimation->company->address }}<br>
                    {{ $estimation->company->zip }} {{ $estimation->company->city }}, {{ $estimation->company->country?->name }}<br>
                    {{ __('estimation.tax_id') }}: {{ $estimation->company->tax_id }}<br>
                    {{ $estimation->company->email }} &mdash; {{ $estimation->company->phone }}
                </div>
            </td>
            <td class="customer">
                <h2>{{ __('estimation.customer') }}</h2>
                <div>
                    <strong>{{ $estimation->customer->name }}</strong><br>
                    @if($estimation->customer->address)
                        {{ $estimation->customer->address }}<br>
                    @endif
                    @if($estimation->customer->zip || $estimation->customer->city)
                        {{ $estimation->customer->zip }} {{ $estimation->customer->city }}<br>
                    @endif
                    @if($estimation->customer->country)
                        {{ $estimation->customer->country->name }}<br>
                    @endif
                    @if($estimation->customer->nif)
                        {{ __('estimation.tax_id') }}: {{ $estimation->customer->nif }}<br>
                    @endif
                </div>
            </td>
            <td class="meta">
                <h1>{{ __('estimation.title') }}</h1>
                <div>{{ __('estimation.number') }}: {{ $estimation->number }}</div>
                <div>{{ __('estimation.date') }}: {{ $estimation->estimation_date->format('d/m/Y') }}</div>
                <div>{{ __('estimation.expiration_date') }}: {{ $estimation->expiration_date->format('d/m/Y') }}</div>
                <div class="badge badge-{{ $estimation->status->value }}">{{ $statusLabel }}</div>
            </td>
        </tr>
    </table>

    <table class="rows">
        <thead>
            <tr>
                <th>{{ __('estimation.description') }}</th>
                <th class="num">{{ __('estimation.quantity') }}</th>
                <th class="num">{{ __('estimation.price') }}</th>
                <th class="num">{{ __('estimation.vat') }}</th>
                <th class="num">{{ __('estimation.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($estimation->rows as $row)
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

    <table class="totals">
        <tr>
            <td>{{ __('estimation.subtotal') }}</td>
            <td class="num">{{ number_format($estimation->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>{{ __('estimation.vat') }}</td>
            <td class="num">{{ number_format($estimation->vat_total, 2) }}</td>
        </tr>
        <tr class="total">
            <td>{{ __('estimation.total') }}</td>
            <td class="num">{{ number_format($estimation->total, 2) }}</td>
        </tr>
    </table>

    @if($bodyHtml !== '')
        <div id="proposal" class="proposal">
            <h2>{{ __('estimation.proposal') }}</h2>
            <div>{!! $bodyHtml !!}</div>
        </div>
    @endif
</body>
</html>
```

- [ ] **Step 5: Add `preview()` and `pdf()` to the controller**

In `app/Http/Controllers/EstimationController.php`, add imports:

```php
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\App;
```

Add the methods:

```php
    public function preview(Estimation $estimation): View
    {
        App::setLocale($estimation->language);

        return view('estimations.template', [
            'estimation' => $estimation->load(['customer.country', 'company.country', 'rows']),
            'bodyHtml' => MarkdownRenderer::toHtml($estimation->body),
        ]);
    }

    public function pdf(Estimation $estimation): HttpResponse
    {
        App::setLocale($estimation->language);

        return Pdf::loadView('estimations.template', [
            'estimation' => $estimation->load(['customer.country', 'company.country', 'rows']),
            'bodyHtml' => MarkdownRenderer::toHtml($estimation->body),
        ])->download(str_replace(['/', '\\'], '-', $estimation->number).'.pdf');
    }
```

- [ ] **Step 6: Add the routes**

In `routes/estimations.php`, add before `Route::resource(...)`:

```php
    Route::get('estimations/{estimation}/preview', [EstimationController::class, 'preview'])->name('estimations.preview');
    Route::get('estimations/{estimation}/pdf', [EstimationController::class, 'pdf'])->name('estimations.pdf');
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/EstimationTemplateTest.php`
Expected: PASS

- [ ] **Step 8: Format, check types, regenerate Wayfinder**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=512M
php artisan wayfinder:generate
```

- [ ] **Step 9: Commit**

```bash
git add resources/views/estimations resources/lang app/Http/Controllers/EstimationController.php routes/estimations.php tests/Feature/EstimationTemplateTest.php resources/js/actions/App/Http/Controllers/EstimationController.ts resources/js/routes/estimations
git commit -m "feat: add estimation PDF template, preview, and download"
```

---

### Task 8: `MarkdownField.vue` — live edit/preview toggle

**Files:**
- Create: `resources/js/components/MarkdownField.vue`
- Modify: `resources/js/pages/estimations/Create.vue`, `Edit.vue`
- Modify: `resources/js/lang/en.ts`, `it.ts`, `es.ts` (add `markdownField.*`)

**Interfaces:**
- Consumes: `EstimationController.markdownPreview()` (wayfinder, from Task 6).
- Produces: `MarkdownField` component — props `modelValue: string`, `label: string`, `placeholder?: string`, `error?: string`; emits `update:modelValue`.

- [ ] **Step 1: Add translation keys**

Add to `resources/js/lang/en.ts`, as a new top-level key (after `passwordInput`):

```ts
    markdownField: {
        edit: 'Edit',
        preview: 'Preview',
        loading: 'Rendering preview…',
        empty: 'Nothing to preview yet.',
    },
```

Italian (`it.ts`):

```ts
    markdownField: {
        edit: 'Modifica',
        preview: 'Anteprima',
        loading: 'Rendering anteprima…',
        empty: 'Niente da mostrare ancora.',
    },
```

Spanish (`es.ts`):

```ts
    markdownField: {
        edit: 'Editar',
        preview: 'Vista previa',
        loading: 'Generando vista previa…',
        empty: 'Nada que previsualizar todavía.',
    },
```

- [ ] **Step 2: Create the component**

```vue
<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EstimationController from '@/actions/App/Http/Controllers/EstimationController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    modelValue: string;
    label: string;
    placeholder?: string;
    error?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const { t } = useI18n();

const mode = ref<'edit' | 'preview'>('edit');
const previewHtml = ref('');
const http = useHttp<{ body: string }, { html: string }>({ body: '' });

async function showPreview(): Promise<void> {
    http.body = props.modelValue;
    const response = await http.submit(EstimationController.markdownPreview());
    previewHtml.value = response.html;
}

function switchTo(next: 'edit' | 'preview'): void {
    mode.value = next;

    if (next === 'preview') {
        void showPreview();
    }
}

function onInput(event: Event): void {
    emit('update:modelValue', (event.target as HTMLTextAreaElement).value);
}
</script>

<template>
    <div class="grid gap-2">
        <div class="flex items-center justify-between">
            <Label>{{ label }}</Label>
            <div class="flex gap-1">
                <Button
                    type="button"
                    size="sm"
                    :variant="mode === 'edit' ? 'secondary' : 'ghost'"
                    @click="switchTo('edit')"
                >
                    {{ t('markdownField.edit') }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :variant="mode === 'preview' ? 'secondary' : 'ghost'"
                    @click="switchTo('preview')"
                >
                    {{ t('markdownField.preview') }}
                </Button>
            </div>
        </div>

        <textarea
            v-if="mode === 'edit'"
            :value="modelValue"
            rows="8"
            class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
            :placeholder="placeholder"
            @input="onInput"
        />
        <div
            v-else
            class="min-h-40 rounded-md border border-input px-3 py-2 text-sm"
        >
            <p v-if="http.processing" class="text-muted-foreground">
                {{ t('markdownField.loading') }}
            </p>
            <div v-else-if="previewHtml" v-html="previewHtml" />
            <p v-else class="text-muted-foreground">
                {{ t('markdownField.empty') }}
            </p>
        </div>

        <InputError :message="error" />
    </div>
</template>
```

- [ ] **Step 3: Wire it into `Create.vue` and `Edit.vue`**

In both files, replace the import list entry for `InputError`-only body field with the new component, and add the import:

```ts
import MarkdownField from '@/components/MarkdownField.vue';
```

Replace the body `<div class="grid gap-2">...textarea...</div>` block in both `Create.vue` and `Edit.vue` with:

```vue
            <MarkdownField
                v-model="form.body"
                :label="t('estimations.create.body')"
                :placeholder="t('estimations.create.bodyPlaceholder')"
                :error="form.errors.body"
            />
```

- [ ] **Step 4: Build and manually verify**

```bash
npm run build
```

Open an estimate's Create or Edit page, type Markdown (e.g. `# Title` and `**bold**`) in the body field, click "Preview", confirm it renders as HTML; click "Edit" to go back to the raw textarea and confirm the text is preserved.

- [ ] **Step 5: Type-check and lint**

```bash
npm run types:check
npm run lint:check
npm run format:check
```

- [ ] **Step 6: Commit**

```bash
git add resources/js/components/MarkdownField.vue resources/js/pages/estimations/Create.vue resources/js/pages/estimations/Edit.vue resources/js/lang
git commit -m "feat: add live markdown edit/preview toggle to estimation forms"
```

---

### Task 9: Attachment upload and delete

**Files:**
- Create: `app/Http/Requests/StoreEstimationAttachmentRequest.php`
- Create: `app/Http/Controllers/EstimationAttachmentController.php`
- Modify: `routes/estimations.php`
- Test: create `tests/Feature/EstimationAttachmentTest.php`

**Interfaces:**
- Consumes: `Attachment` (Task 3), `Estimation` (Task 1).
- Produces: routes `estimations.attachments.store` (`POST estimations/{estimation}/attachments`), `estimations.attachments.destroy` (`DELETE estimations/{estimation}/attachments/{attachment}`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/EstimationAttachmentTest.php`:

```php
<?php

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Estimation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('guests are redirected to the login page when uploading an attachment', function () {
    $estimation = Estimation::factory()->create();

    $this->post(route('estimations.attachments.store', $estimation), [
        'file' => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
    ])->assertRedirect(route('login'));
});

test('an attachment can be uploaded to an estimation', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.attachments.store', $estimation), [
        'file' => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
    ]);

    $response->assertRedirect(route('estimations.edit', $estimation));
    expect($estimation->attachments)->toHaveCount(1);
    expect($estimation->attachments->first()->original_name)->toBe('quote.pdf');
    Storage::disk('local')->assertExists($estimation->attachments->first()->path);
});

test('each allowed extension is accepted', function (string $extension) {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.attachments.store', $estimation), [
        'file' => UploadedFile::fake()->create("file.{$extension}", 100),
    ]);

    $response->assertSessionHasNoErrors();
})->with(['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'rtf', 'md']);

test('a disallowed extension is rejected', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.attachments.store', $estimation), [
        'file' => UploadedFile::fake()->create('script.exe', 100),
    ]);

    $response->assertSessionHasErrors('file');
});

test('a file larger than 10MB is rejected', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.attachments.store', $estimation), [
        'file' => UploadedFile::fake()->create('big.pdf', 10241),
    ]);

    $response->assertSessionHasErrors('file');
});

test('uploading to an estimation from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.attachments.store', $estimation), [
        'file' => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
    ]);

    $response->assertForbidden();
    expect($estimation->attachments)->toHaveCount(0);
});

test('an attachment can be deleted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);
    Storage::disk('local')->put('estimations/x/attachments/file.pdf', 'content');
    $attachment = Attachment::factory()->create([
        'attachable_type' => Estimation::class,
        'attachable_id' => $estimation->id,
        'disk' => 'local',
        'path' => 'estimations/x/attachments/file.pdf',
    ]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('estimations.attachments.destroy', [$estimation, $attachment]));

    $response->assertRedirect(route('estimations.edit', $estimation));
    expect(Attachment::query()->find($attachment->id))->toBeNull();
    Storage::disk('local')->assertMissing('estimations/x/attachments/file.pdf');
});

test('deleting an attachment from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $otherCompany->id]);
    $attachment = Attachment::factory()->create([
        'attachable_type' => Estimation::class,
        'attachable_id' => $estimation->id,
    ]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('estimations.attachments.destroy', [$estimation, $attachment]));

    $response->assertForbidden();
    expect(Attachment::query()->find($attachment->id))->not->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/EstimationAttachmentTest.php`
Expected: FAIL — route `estimations.attachments.store` not defined.

- [ ] **Step 3: Create the request**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEstimationAttachmentRequest extends FormRequest
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'rtf', 'md'];

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
            'file' => [
                'required', 'file', 'max:10240',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $extension = strtolower($value->getClientOriginalExtension());

                    if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                        $fail(__('The file must be one of the following types: :types.', ['types' => implode(', ', self::ALLOWED_EXTENSIONS)]));
                    }
                },
            ],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCurrentCompany;
use App\Http\Requests\StoreEstimationAttachmentRequest;
use App\Models\Attachment;
use App\Models\Estimation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class EstimationAttachmentController extends Controller
{
    use ScopesToCurrentCompany;

    public function store(StoreEstimationAttachmentRequest $request, Estimation $estimation): RedirectResponse
    {
        $this->authorizeCurrentCompany($estimation);

        $file = $request->file('file');
        $path = $file->store("estimations/{$estimation->id}/attachments", 'local');

        $estimation->attachments()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Attachment uploaded.')]);

        return to_route('estimations.edit', $estimation);
    }

    public function destroy(Estimation $estimation, Attachment $attachment): RedirectResponse
    {
        $this->authorizeCurrentCompany($estimation);

        abort_unless(
            $attachment->attachable_type === Estimation::class && $attachment->attachable_id === $estimation->id,
            404
        );

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Attachment deleted.')]);

        return to_route('estimations.edit', $estimation);
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/estimations.php`, add imports and routes:

```php
use App\Http\Controllers\EstimationAttachmentController;
```

```php
    Route::post('estimations/{estimation}/attachments', [EstimationAttachmentController::class, 'store'])->name('estimations.attachments.store');
    Route::delete('estimations/{estimation}/attachments/{attachment}', [EstimationAttachmentController::class, 'destroy'])->name('estimations.attachments.destroy');
```

(Add these two lines before `Route::resource(...)`, alongside the other explicit routes.)

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/EstimationAttachmentTest.php`
Expected: PASS (14 tests, including the 8-extension dataset)

- [ ] **Step 7: Format, check types, regenerate Wayfinder**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=512M
php artisan wayfinder:generate
```

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/StoreEstimationAttachmentRequest.php app/Http/Controllers/EstimationAttachmentController.php routes/estimations.php tests/Feature/EstimationAttachmentTest.php resources/js/actions/App/Http/Controllers/EstimationAttachmentController.ts resources/js/routes/estimations
git commit -m "feat: add estimation attachment upload and delete"
```

---

### Task 10: Attachments panel in the Edit page

**Files:**
- Modify: `resources/js/pages/estimations/Edit.vue`
- Modify: `resources/js/lang/en.ts`, `it.ts`, `es.ts` (add `estimations.edit.attachments.*`)

**Interfaces:**
- Consumes: `EstimationAttachmentController` (wayfinder, from Task 9), `estimation.attachments` prop (already loaded by `EstimationController::edit()` since Task 4).

- [ ] **Step 1: Add translation keys**

Add to the `estimations.edit` block in `resources/js/lang/en.ts`:

```ts
            attachments: {
                title: 'Attachments',
                uploadButton: 'Upload file',
                empty: 'No attachments yet.',
                deleteButton: 'Delete',
                confirmDelete: 'Delete this attachment?',
            },
```

Italian (`it.ts`, inside `estimations.edit`):

```ts
            attachments: {
                title: 'Allegati',
                uploadButton: 'Carica file',
                empty: 'Nessun allegato ancora.',
                deleteButton: 'Elimina',
                confirmDelete: 'Eliminare questo allegato?',
            },
```

Spanish (`es.ts`, inside `estimations.edit`):

```ts
            attachments: {
                title: 'Archivos adjuntos',
                uploadButton: 'Subir archivo',
                empty: 'Todavía no hay archivos adjuntos.',
                deleteButton: 'Eliminar',
                confirmDelete: '¿Eliminar este archivo adjunto?',
            },
```

- [ ] **Step 2: Extend `Edit.vue`**

Add imports:

```ts
import { Trash2, Upload } from '@lucide/vue'; // merge Upload into the existing lucide import
import EstimationAttachmentController from '@/actions/App/Http/Controllers/EstimationAttachmentController';
```

Add a type and prop for attachments:

```ts
type Attachment = {
    id: string;
    original_name: string;
    size: number;
    mime_type: string;
};
```

Add `attachments: Attachment[];` to the `Estimation` type's fields (it is already loaded via `estimation.load(['rows', 'attachments'])` in the controller).

Add refs and handlers in the `<script setup>` block:

```ts
const fileInput = ref<HTMLInputElement | null>(null);

function triggerUpload(): void {
    fileInput.value?.click();
}

function onFileSelected(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    router.post(
        EstimationAttachmentController.store(props.estimation.id).url,
        { file },
        { forceFormData: true, preserveScroll: true },
    );

    (event.target as HTMLInputElement).value = '';
}

function deleteAttachment(attachmentId: string): void {
    if (!confirm(t('estimations.edit.attachments.confirmDelete'))) {
        return;
    }

    router.delete(
        EstimationAttachmentController.destroy([props.estimation.id, attachmentId]).url,
        { preserveScroll: true },
    );
}

function formatSize(bytes: number): string {
    return `${(bytes / 1024).toFixed(0)} KB`;
}
```

Import `ref` from `vue` if not already imported (it isn't in the Task 5 version — add it: `import { computed, ref } from 'vue';`).

Add the panel to the template, after the delete section at the bottom (before the closing `</div>` of the root):

```vue
        <div class="border-t pt-6">
            <div class="flex items-center justify-between">
                <Label>{{ t('estimations.edit.attachments.title') }}</Label>
                <Button type="button" variant="outline" size="sm" @click="triggerUpload">
                    <Upload />
                    {{ t('estimations.edit.attachments.uploadButton') }}
                </Button>
                <input
                    ref="fileInput"
                    type="file"
                    class="hidden"
                    @change="onFileSelected"
                />
            </div>

            <ul v-if="estimation.attachments.length > 0" class="mt-3 divide-y rounded-md border">
                <li
                    v-for="attachment in estimation.attachments"
                    :key="attachment.id"
                    class="flex items-center justify-between px-3 py-2 text-sm"
                >
                    <span class="truncate">
                        {{ attachment.original_name }}
                        <span class="text-muted-foreground">({{ formatSize(attachment.size) }})</span>
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        :title="t('estimations.edit.attachments.deleteButton')"
                        @click="deleteAttachment(attachment.id)"
                    >
                        <Trash2 />
                    </Button>
                </li>
            </ul>
            <p v-else class="mt-3 text-sm text-muted-foreground">
                {{ t('estimations.edit.attachments.empty') }}
            </p>
        </div>
```

- [ ] **Step 3: Build and manually verify**

```bash
npm run build
```

Open an estimate's Edit page, upload a PDF, confirm it appears in the list, delete it, confirm it disappears.

- [ ] **Step 4: Type-check and lint**

```bash
npm run types:check
npm run lint:check
npm run format:check
```

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/estimations/Edit.vue resources/js/lang
git commit -m "feat: add attachments panel to estimation edit page"
```

---

### Task 11: Convert to invoice

**Files:**
- Modify: `app/Http/Controllers/EstimationController.php` (add `convertToInvoice`)
- Modify: `routes/estimations.php`
- Test: create `tests/Feature/EstimationConversionTest.php`

**Interfaces:**
- Consumes: `Invoice`, `InvoiceRow` (existing models).
- Produces: route `estimations.convert-to-invoice` (`POST estimations/{estimation}/convert-to-invoice`), redirecting to `invoices.edit` on success.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/EstimationConversionTest.php`:

```php
<?php

use App\Enums\EstimationStatus;
use App\Models\Company;
use App\Models\Estimation;
use App\Models\EstimationRow;
use App\Models\Invoice;
use App\Models\User;

test('guests are redirected to the login page when converting an estimation', function () {
    $estimation = Estimation::factory()->create();

    $this->post(route('estimations.convert-to-invoice', $estimation))->assertRedirect(route('login'));
});

test('an accepted estimation can be converted to an invoice', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Accepted, 'language' => 'it']);
    EstimationRow::factory()->create(['estimation_id' => $estimation->id, 'description' => 'Consulting', 'quantity' => 2, 'price' => 100, 'vat_rate' => 22]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.convert-to-invoice', $estimation));

    $estimation->refresh();
    expect($estimation->invoice_id)->not->toBeNull();

    $invoice = Invoice::query()->findOrFail($estimation->invoice_id);
    expect($invoice->customer_id)->toBe($estimation->customer_id);
    expect($invoice->company_id)->toBe($company->id);
    expect($invoice->language)->toBe('it');
    expect($invoice->paid)->toBeFalse();
    expect($invoice->rows)->toHaveCount(1);
    expect($invoice->rows->first()->description)->toBe('Consulting');
    expect((float) $invoice->rows->first()->quantity)->toEqual(2.0);

    $response->assertRedirect(route('invoices.edit', $invoice));
});

test('a pending estimation cannot be converted to an invoice', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Pending]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.convert-to-invoice', $estimation));

    expect($estimation->fresh()->invoice_id)->toBeNull();
});

test('an already converted estimation cannot be converted again', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Accepted, 'invoice_id' => $invoice->id]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.convert-to-invoice', $estimation));

    expect(Invoice::count())->toBe(1);
});

test('converting an estimation from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $otherCompany->id, 'status' => EstimationStatus::Accepted]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.convert-to-invoice', $estimation));

    $response->assertForbidden();
    expect($estimation->fresh()->invoice_id)->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/EstimationConversionTest.php`
Expected: FAIL — route `estimations.convert-to-invoice` not defined.

- [ ] **Step 3: Add the controller action**

In `app/Http/Controllers/EstimationController.php`, add the import:

```php
use App\Models\Invoice;
```

Add the method:

```php
    public function convertToInvoice(Estimation $estimation): RedirectResponse
    {
        $this->authorizeCurrentCompany($estimation);

        if ($estimation->status !== EstimationStatus::Accepted || $estimation->invoice_id !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only an accepted, not yet converted estimation can become an invoice.')]);

            return to_route('estimations.edit', $estimation);
        }

        $invoice = DB::transaction(function () use ($estimation): Invoice {
            $invoice = Invoice::query()->create([
                'company_id' => $estimation->company_id,
                'customer_id' => $estimation->customer_id,
                'invoice_date' => now()->toDateString(),
                'paid' => false,
                'language' => $estimation->language,
            ]);

            foreach ($estimation->rows as $row) {
                $invoice->rows()->create([
                    'description' => $row->description,
                    'quantity' => $row->quantity,
                    'price' => $row->price,
                    'vat_rate' => $row->vat_rate,
                ]);
            }

            $estimation->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Estimation converted to invoice.')]);

        return to_route('invoices.edit', $invoice);
    }
```

- [ ] **Step 4: Add the route**

In `routes/estimations.php`, add before `Route::resource(...)`:

```php
    Route::post('estimations/{estimation}/convert-to-invoice', [EstimationController::class, 'convertToInvoice'])->name('estimations.convert-to-invoice');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/EstimationConversionTest.php`
Expected: PASS

- [ ] **Step 6: Format, check types, regenerate Wayfinder**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=512M
php artisan wayfinder:generate
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/EstimationController.php routes/estimations.php tests/Feature/EstimationConversionTest.php resources/js/actions/App/Http/Controllers/EstimationController.ts resources/js/routes/estimations
git commit -m "feat: add estimation to invoice conversion"
```

---

### Task 12: Status select, expired badge, and "Convert to invoice" in the UI

**Files:**
- Create: `resources/js/lib/estimationStatus.ts`
- Modify: `resources/js/pages/estimations/Edit.vue`
- Modify: `resources/js/lang/en.ts`, `it.ts`, `es.ts` (add `estimations.edit.convertButton`, `.convertedNotice`, `.expiredBadge`)

**Interfaces:**
- Consumes: `EstimationController.convertToInvoice()` (wayfinder, from Task 11).
- Produces: `estimationStatusBadgeVariant(status): 'default' | 'secondary' | 'destructive'` (shared by Index.vue in Task 14 to avoid duplicating the mapping written inline there in Task 5).

- [ ] **Step 1: Add translation keys**

Add to the `estimations.edit` block in `resources/js/lang/en.ts`:

```ts
            convertButton: 'Convert to invoice',
            convertedNotice: 'Converted to invoice',
            expiredBadge: 'Expired',
```

Italian (`it.ts`, inside `estimations.edit`):

```ts
            convertButton: 'Converti in fattura',
            convertedNotice: 'Convertito in fattura',
            expiredBadge: 'Scaduto',
```

Spanish (`es.ts`, inside `estimations.edit`):

```ts
            convertButton: 'Convertir en factura',
            convertedNotice: 'Convertido en factura',
            expiredBadge: 'Caducado',
```

- [ ] **Step 2: Create the shared status helper**

```ts
export type EstimationStatus = 'pending' | 'accepted' | 'rejected';

export function estimationStatusBadgeVariant(
    status: EstimationStatus,
): 'default' | 'secondary' | 'destructive' {
    if (status === 'accepted') {
        return 'default';
    }

    if (status === 'rejected') {
        return 'destructive';
    }

    return 'secondary';
}
```

- [ ] **Step 3: Extend `Edit.vue`**

Replace the inline `statusBadgeVariant` usage plan with the shared helper (Edit.vue doesn't have one yet from Task 5 — this is the first use there). Add imports:

```ts
import InvoiceController from '@/actions/App/Http/Controllers/InvoiceController'; // for the "view invoice" link
import EstimationController from '@/actions/App/Http/Controllers/EstimationController'; // already imported — just add the extra usage below
import { Badge } from '@/components/ui/badge';
import { estimationStatusBadgeVariant } from '@/lib/estimationStatus';
import { edit as editInvoice } from '@/routes/invoices';
```

Add to the `Estimation` type: `is_expired: boolean;` and `invoice_id: string | null;` (the latter already exists from Task 5).

The controller must expose `is_expired` in the Inertia payload — update `EstimationController::edit()` from Task 4 to include it explicitly, since the model accessor isn't appended by default:

```php
        return Inertia::render('estimations/Edit', [
            'estimation' => $estimation->load(['rows', 'attachments'])->append('is_expired'),
            'customers' => Customer::query()->where('company_id', $estimation->company_id)->orderBy('name')->get(['id', 'name']),
        ]);
```

Add a computed and a handler in `Edit.vue`'s `<script setup>`:

```ts
const canConvert = computed(
    () => props.estimation.status === 'accepted' && !isConverted.value,
);

function convertToInvoice(): void {
    router.post(EstimationController.convertToInvoice(props.estimation.id).url);
}
```

Add a status/expiry indicator and the convert action to the template, right after the `<Heading>` block:

```vue
        <div class="flex items-center gap-2">
            <Badge :variant="estimationStatusBadgeVariant(estimation.status)">
                {{ t(`estimations.status.${estimation.status}`) }}
            </Badge>
            <Badge v-if="estimation.is_expired" variant="outline">
                {{ t('estimations.edit.expiredBadge') }}
            </Badge>
        </div>

        <div v-if="isConverted">
            <Link
                :href="editInvoice(estimation.invoice_id!)"
                class="text-sm text-primary hover:underline"
            >
                {{ t('estimations.edit.convertedNotice') }}
            </Link>
        </div>
        <Button
            v-else-if="canConvert"
            type="button"
            variant="outline"
            size="sm"
            @click="convertToInvoice"
        >
            {{ t('estimations.edit.convertButton') }}
        </Button>
```

- [ ] **Step 4: Build and manually verify**

```bash
npm run build
```

Create an estimate, set its status to "Accepted" and save, reopen it, click "Convert to invoice", confirm it redirects to the new invoice's edit page and that reopening the estimate now shows "Converted to invoice" linking to it (and the form is disabled).

- [ ] **Step 5: Type-check and lint**

```bash
npm run types:check
npm run lint:check
npm run format:check
```

- [ ] **Step 6: Run the backend test suite (the `edit()` payload change touches Task 4's tests)**

Run: `php artisan test --compact tests/Feature/EstimationTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add resources/js/lib/estimationStatus.ts resources/js/pages/estimations/Edit.vue resources/js/lang app/Http/Controllers/EstimationController.php
git commit -m "feat: show status/expiry and convert-to-invoice action on estimation edit page"
```

---

### Task 13: ZIP export (PDF + attachments)

**Files:**
- Modify: `app/Http/Controllers/EstimationController.php` (add `zip`)
- Modify: `routes/estimations.php`
- Test: create `tests/Feature/EstimationZipTest.php`

**Interfaces:**
- Produces: route `estimations.zip` (`GET estimations/{estimation}/zip`), downloading a ZIP containing `{number}.pdf` plus every attachment under its `original_name`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/EstimationZipTest.php`:

```php
<?php

use App\Models\Attachment;
use App\Models\Estimation;
use App\Models\EstimationRow;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('guests are redirected to the login page when downloading an estimation zip', function () {
    $estimation = Estimation::factory()->create();

    $this->get(route('estimations.zip', $estimation))->assertRedirect(route('login'));
});

test('the zip contains the pdf and every attachment', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $estimation = Estimation::factory()->create();
    EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    Storage::disk('local')->put('estimations/x/attachments/brief.pdf', 'brief content');
    Storage::disk('local')->put('estimations/x/attachments/logo.png', 'png content');
    Attachment::factory()->create([
        'attachable_type' => Estimation::class,
        'attachable_id' => $estimation->id,
        'disk' => 'local',
        'path' => 'estimations/x/attachments/brief.pdf',
        'original_name' => 'brief.pdf',
    ]);
    Attachment::factory()->create([
        'attachable_type' => Estimation::class,
        'attachable_id' => $estimation->id,
        'disk' => 'local',
        'path' => 'estimations/x/attachments/logo.png',
        'original_name' => 'logo.png',
    ]);

    $response = $this->actingAs($user)->get(route('estimations.zip', $estimation));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/zip');

    $tmpFile = tempnam(sys_get_temp_dir(), 'zip-test-');
    file_put_contents($tmpFile, $response->streamedContent());

    $zip = new ZipArchive();
    $zip->open($tmpFile);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    unlink($tmpFile);

    expect($names)->toContain($estimation->number.'.pdf');
    expect($names)->toContain('brief.pdf');
    expect($names)->toContain('logo.png');
});

test('the zip works for an estimation with no attachments', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $estimation = Estimation::factory()->create();
    EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $response = $this->actingAs($user)->get(route('estimations.zip', $estimation));

    $response->assertOk();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/EstimationZipTest.php`
Expected: FAIL — route `estimations.zip` not defined.

- [ ] **Step 3: Add the controller action**

In `app/Http/Controllers/EstimationController.php`, add the import:

```php
use ZipArchive;
```

Add the method:

```php
    public function zip(Estimation $estimation): HttpResponse
    {
        App::setLocale($estimation->language);

        $estimation->load(['customer.country', 'company.country', 'rows', 'attachments']);

        $pdfContent = Pdf::loadView('estimations.template', [
            'estimation' => $estimation,
            'bodyHtml' => MarkdownRenderer::toHtml($estimation->body),
        ])->output();

        $zipPath = tempnam(sys_get_temp_dir(), 'estimation-zip-');
        $number = str_replace(['/', '\\'], '-', $estimation->number);

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString("{$number}.pdf", $pdfContent);

        foreach ($estimation->attachments as $attachment) {
            $zip->addFile(Storage::disk($attachment->disk)->path($attachment->path), $attachment->original_name);
        }

        $zip->close();

        return response()->download($zipPath, "{$number}.zip")->deleteFileAfterSend();
    }
```

Add `use Illuminate\Support\Facades\Storage;` to the controller's imports if not already present.

- [ ] **Step 4: Add the route**

In `routes/estimations.php`, add before `Route::resource(...)`:

```php
    Route::get('estimations/{estimation}/zip', [EstimationController::class, 'zip'])->name('estimations.zip');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/EstimationZipTest.php`
Expected: PASS

- [ ] **Step 6: Format, check types, regenerate Wayfinder**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=512M
php artisan wayfinder:generate
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/EstimationController.php routes/estimations.php tests/Feature/EstimationZipTest.php resources/js/actions/App/Http/Controllers/EstimationController.ts resources/js/routes/estimations
git commit -m "feat: add estimation zip export (pdf + attachments)"
```

---

### Task 14: Preview/PDF/ZIP/Duplicate actions in the UI

**Files:**
- Modify: `resources/js/pages/estimations/Index.vue`, `Edit.vue`
- Modify: `resources/js/lang/en.ts`, `it.ts`, `es.ts` (add `estimations.index.preview/pdf/zip/duplicate`)

**Interfaces:**
- Consumes: `EstimationController.preview()`, `.pdf()`, `.zip()` (wayfinder, from Tasks 7/13), `estimationStatusBadgeVariant` (Task 12), `?duplicate=` support already in `EstimationController::create()` (Task 4).

- [ ] **Step 1: Add translation keys**

Add to `estimations.index` in `resources/js/lang/en.ts`:

```ts
            preview: 'Preview',
            pdf: 'PDF',
            zip: 'ZIP',
            duplicate: 'Duplicate',
```

Italian (`it.ts`, inside `estimations.index`):

```ts
            preview: 'Anteprima',
            pdf: 'PDF',
            zip: 'ZIP',
            duplicate: 'Duplica',
```

Spanish (`es.ts`, inside `estimations.index`):

```ts
            preview: 'Vista previa',
            pdf: 'PDF',
            zip: 'ZIP',
            duplicate: 'Duplicar',
```

- [ ] **Step 2: Update `Index.vue`**

Replace the local `statusBadgeVariant` function with the shared helper. Update imports:

```ts
import { Copy, Eye, FileArchive, FileText, Pencil, Plus } from '@lucide/vue';
import EstimationController from '@/actions/App/Http/Controllers/EstimationController';
import { estimationStatusBadgeVariant } from '@/lib/estimationStatus';
```

Remove the inline `statusBadgeVariant` function (superseded by the import) and update its usage in the template to `estimationStatusBadgeVariant(estimation.status)`.

Replace the actions cell:

```vue
                        <td class="space-x-1 px-4 py-2 text-right">
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :title="t('estimations.index.preview')"
                            >
                                <a
                                    :href="EstimationController.preview(estimation.id).url"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <Eye />
                                </a>
                            </Button>
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :title="t('estimations.index.pdf')"
                            >
                                <a :href="EstimationController.pdf(estimation.id).url">
                                    <FileText />
                                </a>
                            </Button>
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :title="t('estimations.index.zip')"
                            >
                                <a :href="EstimationController.zip(estimation.id).url">
                                    <FileArchive />
                                </a>
                            </Button>
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :title="t('common.actions.edit')"
                            >
                                <Link :href="edit(estimation.id)">
                                    <Pencil />
                                </Link>
                            </Button>
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :title="t('estimations.index.duplicate')"
                            >
                                <Link
                                    :href="
                                        create({
                                            query: { duplicate: estimation.id },
                                        })
                                    "
                                >
                                    <Copy />
                                </Link>
                            </Button>
                        </td>
```

- [ ] **Step 3: Update `Edit.vue`**

Add the preview/PDF/ZIP icon row, mirroring `invoices/Edit.vue`, right after the `<Heading>` block and before the status/expiry badges added in Task 12:

```ts
import { Eye, FileArchive, FileText } from '@lucide/vue'; // merge into the existing lucide import
```

```vue
        <div class="flex gap-1">
            <Button
                as-child
                variant="ghost"
                size="icon-sm"
                :title="t('estimations.index.preview')"
            >
                <a
                    :href="EstimationController.preview(estimation.id).url"
                    target="_blank"
                    rel="noopener"
                >
                    <Eye />
                </a>
            </Button>
            <Button
                as-child
                variant="ghost"
                size="icon-sm"
                :title="t('estimations.index.pdf')"
            >
                <a :href="EstimationController.pdf(estimation.id).url">
                    <FileText />
                </a>
            </Button>
            <Button
                as-child
                variant="ghost"
                size="icon-sm"
                :title="t('estimations.index.zip')"
            >
                <a :href="EstimationController.zip(estimation.id).url">
                    <FileArchive />
                </a>
            </Button>
        </div>
```

- [ ] **Step 4: Build and manually verify**

```bash
npm run build
```

From the estimates list, click Preview (opens HTML in a new tab), PDF (downloads), ZIP (downloads a zip containing the PDF and any attachments), Duplicate (opens Create pre-filled), Edit. Repeat Preview/PDF/ZIP from inside the Edit page.

- [ ] **Step 5: Type-check and lint**

```bash
npm run types:check
npm run lint:check
npm run format:check
```

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/estimations/Index.vue resources/js/pages/estimations/Edit.vue resources/js/lang
git commit -m "feat: add preview, pdf, zip, and duplicate actions to estimation pages"
```

---

### Task 15: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full backend test suite**

```bash
php artisan test --compact
```

Expected: all tests pass (previous suite of 238 plus every new test added in Tasks 1–13).

- [ ] **Step 2: Run Pint**

```bash
vendor/bin/pint --format agent
```

Expected: no changes needed (or auto-fixed and re-verified).

- [ ] **Step 3: Run PHPStan**

```bash
vendor/bin/phpstan analyse --memory-limit=512M
```

Expected: 0 errors.

- [ ] **Step 4: Run the frontend checks**

```bash
npm run types:check
npm run lint:check
npm run format:check
npm run build
```

Expected: all pass, production build succeeds.

- [ ] **Step 5: Manual end-to-end walkthrough**

Log in, create a company if needed, create a customer, create an estimate with two rows and a Markdown proposal body, upload two attachments (one `.pdf`, one `.md`), preview it, download the PDF, download the ZIP and confirm it contains both the PDF and the two attachments, set status to "Accepted", convert it to an invoice, confirm the invoice has the right rows/customer/total, reopen the estimate and confirm it now shows "Converted to invoice" and the form is read-only, confirm it can no longer be deleted.

- [ ] **Step 6: Final commit (if any cleanup was needed)**

```bash
git add -A
git commit -m "chore: final verification pass for estimations feature"
```

(Skip this commit if Steps 1–5 required no changes.)
