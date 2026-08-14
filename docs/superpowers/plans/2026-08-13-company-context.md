# Company Context Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the "operating company" selection out of the invoice form and into a global header switcher whose choice (stored in the session) scopes invoice numbering, and filters/assigns both invoices and products.

**Architecture:** A new `App\Support\CurrentCompany::resolve()` service reads `session('current_company_id')`, falling back to the `is_default` company, then the first company by name, then `null` when no company exists at all. `HandleInertiaRequests` shares the resolved company plus the full company list on every page, powering a new `CompanySwitcher.vue` dropdown in the header that posts to a small `PUT /current-company` endpoint. Invoice and Product controllers stop accepting `company_id` from client input entirely — they read it from `CurrentCompany::resolve()`, filter their `index()` queries by it, and `abort(403)` on `edit`/`update`/`destroy` when a record belongs to a different company. `Invoice::nextNumber()` becomes scoped by `company_id`, backed by a `unique(['company_id', 'number'])` constraint. `products` gains a required `company_id` column.

**Tech Stack:** Laravel 13, Inertia v3 (Vue 3), Pest 5, Wayfinder (route/action codegen), vue-i18n.

**Spec:** [docs/superpowers/specs/2026-08-13-company-context-design.md](../specs/2026-08-13-company-context-design.md)

## Global Constraints

- No per-user company scoping: the "current company" is a single session value shared by whoever is logged in, not tied to a user record.
- `CurrentCompany::resolve()` must be nullable — a fresh install with zero companies is a valid, handled state (redirect to `companies.create`, not a crash).
- Company selection is never sent from the invoice or product forms again; the server is the sole source of truth for `company_id` on create.
- `edit`/`update`/`destroy` on an invoice or product belonging to a different company than the current one must `abort(403)`.
- Dev/test data may be treated as disposable (per user decision) — no defensive data-migration ceremony beyond the `products.company_id` backfill already specified.
- Run `vendor/bin/pint --dirty --format agent` after any PHP change, per project convention. Run `php artisan test --compact --filter=<Name>` after each task's test file changes.

---

## Task 1: Products belong to a company

**Files:**
- Create: `database/migrations/2026_08_13_120000_add_company_id_to_products_table.php`
- Modify: `app/Models/Product.php`
- Modify: `app/Models/Company.php`
- Modify: `database/factories/ProductFactory.php`
- Modify: `tests/Feature/ProductTest.php`

**Interfaces:**
- Produces: `Product::company(): BelongsTo<Company, Product>`, `Company::products(): HasMany<Product, Company>`, `products.company_id` (uuid, FK, NOT NULL, `restrictOnDelete`).

- [ ] **Step 1: Write the failing tests**

Add these to `tests/Feature/ProductTest.php`, right after the existing `test('product factory creates a product', ...)` test:

```php
test('product belongs to a company', function () {
    $company = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $company->id]);

    expect($product->company)->toBeInstanceOf(Company::class);
    expect($product->company->id)->toBe($company->id);
});

test('a company can have many products', function () {
    $company = Company::factory()->create();
    Product::factory()->count(2)->create(['company_id' => $company->id]);

    expect($company->fresh()->products)->toHaveCount(2);
});

test('a product requires a company_id at the database level', function () {
    expect(fn () => Product::factory()->create(['company_id' => null]))
        ->toThrow(QueryException::class);
});
```

Add these imports to the top of the file:

```php
use App\Models\Company;
use Illuminate\Database\QueryException;
```

(`App\Enums\ProductType` and `App\Models\Product` are already imported.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=ProductTest`
Expected: FAIL — `company_id` column / `company()` relation / `products()` relation don't exist yet.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignUuid('company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        $defaultCompanyId = DB::table('companies')->where('is_default', true)->value('id')
            ?? DB::table('companies')->orderBy('name')->value('id');

        if ($defaultCompanyId !== null) {
            DB::table('products')->update(['company_id' => $defaultCompanyId]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
```

- [ ] **Step 4: Update the Product model**

Replace the full contents of `app/Models/Product.php`:

```php
<?php

namespace App\Models;

use App\Enums\ProductType;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $code
 * @property ProductType $type
 * @property string $description
 * @property float $price
 * @property string $company_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'type', 'description', 'price', 'company_id'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'price' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
```

- [ ] **Step 5: Add the inverse relation on Company**

In `app/Models/Company.php`, replace:

```php
    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
```

with:

```php
    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
```

- [ ] **Step 6: Update the factory**

Replace the full contents of `database/factories/ProductFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('PRD-####'),
            'type' => fake()->randomElement(ProductType::cases()),
            'description' => fake()->words(3, true),
            'price' => fake()->randomFloat(2, 1, 1000),
            'company_id' => Company::factory(),
        ];
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=ProductTest`
Expected: PASS (all tests, including the pre-existing ones — `Product::factory()->create()` now creates its own company via the factory default).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_13_120000_add_company_id_to_products_table.php app/Models/Product.php app/Models/Company.php database/factories/ProductFactory.php tests/Feature/ProductTest.php
git commit -m "feat: add company_id to products"
```

---

## Task 2: CurrentCompany resolver service

**Files:**
- Create: `app/Support/CurrentCompany.php`
- Create: `tests/Feature/CurrentCompanyTest.php`

**Interfaces:**
- Produces: `App\Support\CurrentCompany::resolve(): ?Company` — session id if valid, else `is_default` company, else first company by name, else `null`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Support\Str;

test('resolve uses the company stored in the session when it exists', function () {
    $company = Company::factory()->create();
    Company::factory()->create(['is_default' => true]);

    session(['current_company_id' => $company->id]);

    expect(CurrentCompany::resolve()->id)->toBe($company->id);
});

test('resolve falls back to the default company when the session is empty', function () {
    Company::factory()->create();
    $default = Company::factory()->create(['is_default' => true]);

    expect(CurrentCompany::resolve()->id)->toBe($default->id);
});

test('resolve falls back to the first company by name when there is no default', function () {
    Company::factory()->create(['name' => 'Bravo Inc']);
    $alpha = Company::factory()->create(['name' => 'Alpha Inc']);

    expect(CurrentCompany::resolve()->id)->toBe($alpha->id);
});

test('resolve falls back to the default company when the session id does not exist', function () {
    $default = Company::factory()->create(['is_default' => true]);

    session(['current_company_id' => (string) Str::uuid()]);

    expect(CurrentCompany::resolve()->id)->toBe($default->id);
});

test('resolve returns null when there are no companies at all', function () {
    expect(CurrentCompany::resolve())->toBeNull();
});
```

Save as `tests/Feature/CurrentCompanyTest.php`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=CurrentCompanyTest`
Expected: FAIL — `App\Support\CurrentCompany` class not found.

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Support;

use App\Models\Company;

class CurrentCompany
{
    public static function resolve(): ?Company
    {
        $sessionId = session('current_company_id');

        if (is_string($sessionId)) {
            $company = Company::query()->find($sessionId);

            if ($company !== null) {
                return $company;
            }
        }

        return Company::query()->where('is_default', true)->first()
            ?? Company::query()->orderBy('name')->first();
    }
}
```

Save as `app/Support/CurrentCompany.php`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=CurrentCompanyTest`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/CurrentCompany.php tests/Feature/CurrentCompanyTest.php
git commit -m "feat: add CurrentCompany resolver service"
```

---

## Task 3: Share the current company globally via Inertia

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Create: `resources/js/types/company.ts`
- Modify: `resources/js/types/index.ts`
- Modify: `resources/js/types/global.d.ts`
- Modify: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `App\Support\CurrentCompany::resolve()` (Task 2).
- Produces: Inertia shared props `currentCompany: {id: string; name: string} | null` and `companies: {id: string; name: string}[]`, available via `usePage().props.currentCompany` / `.companies` on every page. TS type `Company` exported from `@/types`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/DashboardTest.php`:

```php
test('dashboard shares the current company and the full company list', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['is_default' => true]);
    Company::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('currentCompany.id', $company->id)
        ->where('currentCompany.name', $company->name)
        ->has('companies', 2)
    );
});

test('dashboard shares a null current company when none exist', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('currentCompany', null));
});
```

Add `use App\Models\Company;` to the top of the file (`use App\Models\User;` is already there).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=DashboardTest`
Expected: FAIL — `currentCompany`/`companies` props missing.

- [ ] **Step 3: Share the props from the middleware**

Replace the full contents of `app/Http/Middleware/HandleInertiaRequests.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $currentCompany = CurrentCompany::resolve();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'version' => config('app.version'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'locale' => app()->getLocale(),
            'locales' => SetLocale::SUPPORTED_LOCALES,
            'currentCompany' => $currentCompany ? [
                'id' => $currentCompany->id,
                'name' => $currentCompany->name,
            ] : null,
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=DashboardTest`
Expected: PASS

- [ ] **Step 5: Add the TypeScript types**

Create `resources/js/types/company.ts`:

```ts
export type Company = {
    id: string;
    name: string;
};
```

In `resources/js/types/index.ts`, replace:

```ts
export * from './auth';
export * from './navigation';
export * from './ui';
```

with:

```ts
export * from './auth';
export * from './company';
export * from './navigation';
export * from './ui';
```

In `resources/js/types/global.d.ts`, replace:

```ts
import type { Auth } from '@/types/auth';
```

with:

```ts
import type { Auth } from '@/types/auth';
import type { Company } from '@/types/company';
```

and replace:

```ts
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            version: string | null;
            auth: Auth;
            sidebarOpen: boolean;
            locale: string;
            locales: string[];
            [key: string]: unknown;
        };
    }
}
```

with:

```ts
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            version: string | null;
            auth: Auth;
            sidebarOpen: boolean;
            locale: string;
            locales: string[];
            currentCompany: Company | null;
            companies: Company[];
            [key: string]: unknown;
        };
    }
}
```

- [ ] **Step 6: Type-check the frontend**

Run: `npm run types:check`
Expected: no new errors.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/HandleInertiaRequests.php resources/js/types/company.ts resources/js/types/index.ts resources/js/types/global.d.ts tests/Feature/DashboardTest.php
git commit -m "feat: share the current company and company list via Inertia"
```

---

## Task 4: Company switch endpoint

**Files:**
- Create: `app/Http/Controllers/CurrentCompanyController.php`
- Create: `app/Http/Requests/UpdateCurrentCompanyRequest.php`
- Modify: `routes/companies.php`
- Modify: `tests/Feature/CurrentCompanyTest.php`

**Interfaces:**
- Produces: `PUT /current-company` (route name `current-company.update`), body `{ company_id: string }`, sets `session('current_company_id')`, redirects back. 422 on invalid/missing `company_id`. Generates `resources/js/actions/App/Http/Controllers/CurrentCompanyController.ts` via Wayfinder.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/CurrentCompanyTest.php`:

```php
test('switching the current company updates the session', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->put(route('current-company.update'), [
        'company_id' => $company->id,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect();
    expect(session('current_company_id'))->toBe($company->id);
});

test('switching to a company that does not exist fails validation', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->put(route('current-company.update'), [
        'company_id' => (string) Str::uuid(),
    ]);

    $response->assertSessionHasErrors('company_id');
});

test('switching the current company without a company_id fails validation', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->put(route('current-company.update'), []);

    $response->assertSessionHasErrors('company_id');
});

test('guests are redirected to the login page when switching the current company', function () {
    $company = Company::factory()->create();

    $response = $this->put(route('current-company.update'), ['company_id' => $company->id]);

    $response->assertRedirect(route('login'));
});
```

Add `use App\Models\User;` to the top of the file (`Str` is already imported from Task 2).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=CurrentCompanyTest`
Expected: FAIL — route `current-company.update` does not exist.

- [ ] **Step 3: Create the form request**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrentCompanyRequest extends FormRequest
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
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
        ];
    }
}
```

Save as `app/Http/Requests/UpdateCurrentCompanyRequest.php`.

- [ ] **Step 4: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCurrentCompanyRequest;
use Illuminate\Http\RedirectResponse;

class CurrentCompanyController extends Controller
{
    public function update(UpdateCurrentCompanyRequest $request): RedirectResponse
    {
        session(['current_company_id' => $request->validated('company_id')]);

        return back();
    }
}
```

Save as `app/Http/Controllers/CurrentCompanyController.php`.

- [ ] **Step 5: Register the route**

Replace the full contents of `routes/companies.php`:

```php
<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CurrentCompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::put('current-company', [CurrentCompanyController::class, 'update'])->name('current-company.update');
    Route::resource('companies', CompanyController::class)->except('show');
});
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=CurrentCompanyTest`
Expected: PASS

- [ ] **Step 7: Generate the Wayfinder TypeScript action**

Run: `php artisan wayfinder:generate`
Expected: creates/updates `resources/js/actions/App/Http/Controllers/CurrentCompanyController.ts` and `resources/js/routes/current-company/index.ts` (or equivalent) with an `update` export.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/CurrentCompanyController.php app/Http/Requests/UpdateCurrentCompanyRequest.php routes/companies.php tests/Feature/CurrentCompanyTest.php resources/js/actions/App/Http/Controllers/CurrentCompanyController.ts resources/js/routes
git commit -m "feat: add endpoint to switch the current company"
```

---

## Task 5: Company switcher in the header

**Files:**
- Create: `resources/js/components/CompanySwitcher.vue`
- Modify: `resources/js/components/AppHeader.vue`
- Modify: `resources/js/lang/en.ts`
- Modify: `resources/js/lang/it.ts`
- Modify: `resources/js/lang/es.ts`

**Interfaces:**
- Consumes: shared Inertia props `currentCompany`, `companies` (Task 3); `CurrentCompanyController.update.url()` (Task 4); `create()` from `@/routes/companies`.
- Produces: `<CompanySwitcher />`, a header dropdown when `companies.length > 0`, else a "create your first company" link.

- [ ] **Step 1: Add the translation keys**

In `resources/js/lang/en.ts`, replace:

```ts
        edit: {
            title: 'Edit company',
            description: 'Update {name}',
            currentLogoAlt: 'Current logo',
            removeLogo: 'Remove current logo',
            confirmDelete: 'Delete this company? This cannot be undone.',
            deleteButton: 'Delete company',
        },
    },
    countries: {
```

with:

```ts
        edit: {
            title: 'Edit company',
            description: 'Update {name}',
            currentLogoAlt: 'Current logo',
            removeLogo: 'Remove current logo',
            confirmDelete: 'Delete this company? This cannot be undone.',
            deleteButton: 'Delete company',
        },
    },
    companySwitcher: {
        label: 'Company',
        createFirst: 'Create your first company',
    },
    countries: {
```

In `resources/js/lang/it.ts`, replace:

```ts
        edit: {
            title: 'Modifica azienda',
            description: 'Aggiorna {name}',
            currentLogoAlt: 'Logo attuale',
            removeLogo: 'Rimuovi logo attuale',
            confirmDelete:
                "Eliminare questa azienda? L'azione non può essere annullata.",
            deleteButton: 'Elimina azienda',
        },
    },
    countries: {
```

with:

```ts
        edit: {
            title: 'Modifica azienda',
            description: 'Aggiorna {name}',
            currentLogoAlt: 'Logo attuale',
            removeLogo: 'Rimuovi logo attuale',
            confirmDelete:
                "Eliminare questa azienda? L'azione non può essere annullata.",
            deleteButton: 'Elimina azienda',
        },
    },
    companySwitcher: {
        label: 'Azienda',
        createFirst: 'Crea la tua prima azienda',
    },
    countries: {
```

In `resources/js/lang/es.ts`, replace:

```ts
        edit: {
            title: 'Editar empresa',
            description: 'Actualizar {name}',
            currentLogoAlt: 'Logotipo actual',
            removeLogo: 'Eliminar logotipo actual',
            confirmDelete:
                '¿Eliminar esta empresa? Esta acción no se puede deshacer.',
            deleteButton: 'Eliminar empresa',
        },
    },
    countries: {
```

with:

```ts
        edit: {
            title: 'Editar empresa',
            description: 'Actualizar {name}',
            currentLogoAlt: 'Logotipo actual',
            removeLogo: 'Eliminar logotipo actual',
            confirmDelete:
                '¿Eliminar esta empresa? Esta acción no se puede deshacer.',
            deleteButton: 'Eliminar empresa',
        },
    },
    companySwitcher: {
        label: 'Empresa',
        createFirst: 'Crea tu primera empresa',
    },
    countries: {
```

- [ ] **Step 2: Create the CompanySwitcher component**

```vue
<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Building2, ChevronDown, Plus } from '@lucide/vue';
import type { AcceptableValue } from 'reka-ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CurrentCompanyController from '@/actions/App/Http/Controllers/CurrentCompanyController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { create } from '@/routes/companies';

const { t } = useI18n();

const page = usePage();
const currentCompany = computed(() => page.props.currentCompany);
const companies = computed(() => page.props.companies);

function selectCompany(value: AcceptableValue): void {
    if (typeof value !== 'string' || value === currentCompany.value?.id) {
        return;
    }

    router.put(
        CurrentCompanyController.update.url(),
        { company_id: value },
        { preserveScroll: true },
    );
}
</script>

<template>
    <DropdownMenu v-if="companies.length > 0">
        <DropdownMenuTrigger :as-child="true">
            <Button variant="ghost" size="sm" class="gap-2">
                <Building2 class="size-4" />
                <span class="max-w-40 truncate">{{
                    currentCompany?.name
                }}</span>
                <ChevronDown class="size-3 opacity-60" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuLabel>{{
                t('companySwitcher.label')
            }}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuRadioGroup
                :model-value="currentCompany?.id"
                @update:model-value="selectCompany"
            >
                <DropdownMenuRadioItem
                    v-for="company in companies"
                    :key="company.id"
                    :value="company.id"
                >
                    {{ company.name }}
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
    <Button v-else as-child variant="ghost" size="sm" class="gap-2">
        <Link :href="create()">
            <Plus class="size-4" />
            {{ t('companySwitcher.createFirst') }}
        </Link>
    </Button>
</template>
```

Save as `resources/js/components/CompanySwitcher.vue`.

- [ ] **Step 3: Wire it into the header**

In `resources/js/components/AppHeader.vue`, replace:

```ts
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
```

with:

```ts
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import CompanySwitcher from '@/components/CompanySwitcher.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
```

and replace:

```vue
                    <DropdownMenu>
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="relative size-10 w-auto rounded-full p-1 focus-within:ring-2 focus-within:ring-primary"
                            >
```

with:

```vue
                    <CompanySwitcher />

                    <DropdownMenu>
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="relative size-10 w-auto rounded-full p-1 focus-within:ring-2 focus-within:ring-primary"
                            >
```

- [ ] **Step 4: Type-check and lint the frontend**

Run: `npm run types:check`
Run: `npm run lint:check`
Expected: no new errors.

- [ ] **Step 5: Manually verify in the browser**

Start the app (`composer run dev` or `npm run dev` alongside `php artisan serve`), log in, and confirm: the switcher shows the current company name in the header, opening it lists all companies with the current one checked, selecting another company updates the header and reloads shared data, and — with zero companies in the DB — it shows a "create your first company" link instead.

- [ ] **Step 6: Commit**

```bash
git add resources/js/components/CompanySwitcher.vue resources/js/components/AppHeader.vue resources/js/lang/en.ts resources/js/lang/it.ts resources/js/lang/es.ts
git commit -m "feat: add company switcher to the header"
```

---

## Task 6: Invoices become company-scoped

**Files:**
- Create: `database/migrations/2026_08_13_120100_make_invoice_number_unique_per_company.php`
- Modify: `app/Models/Invoice.php`
- Modify: `app/Http/Controllers/InvoiceController.php`
- Modify: `app/Http/Requests/StoreInvoiceRequest.php`
- Modify: `app/Http/Requests/UpdateInvoiceRequest.php`
- Modify: `resources/js/pages/invoices/Create.vue`
- Modify: `resources/js/pages/invoices/Edit.vue`
- Modify: `resources/lang/it.json`
- Modify: `resources/lang/es.json`
- Modify: `tests/Feature/InvoiceTest.php`

**Interfaces:**
- Consumes: `App\Support\CurrentCompany::resolve()` (Task 2).
- Produces: `Invoice::nextNumber(string $companyId, ?string $year = null): string` (signature change — was `nextNumber(?string $year = null)`). Invoice `store`/`create` never read `company_id` from the client; `index` filters by current company; `edit`/`update`/`destroy` `abort(403)` on a foreign company's invoice; `create`/`store` redirect to `companies.create` when no company exists.

- [ ] **Step 1: Replace the test file**

Replace the full contents of `tests/Feature/InvoiceTest.php`:

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
    $company = Company::factory()->create();

    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'number' => null]);

    expect($invoice->number)->toBe('2026-0001');

    Carbon::setTestNow();
});

test('subsequent invoices in the same year increment the sequence', function () {
    Carbon::setTestNow('2026-01-15');
    $company = Company::factory()->create();

    Invoice::factory()->create(['company_id' => $company->id, 'number' => null]);
    $second = Invoice::factory()->create(['company_id' => $company->id, 'number' => null]);
    $third = Invoice::factory()->create(['company_id' => $company->id, 'number' => null]);

    expect($second->number)->toBe('2026-0002');
    expect($third->number)->toBe('2026-0003');

    Carbon::setTestNow();
});

test('a new calendar year resets the sequence to 0001', function () {
    $company = Company::factory()->create();

    Carbon::setTestNow('2026-12-31');
    Invoice::factory()->create(['company_id' => $company->id, 'number' => null]);

    Carbon::setTestNow('2027-01-01');
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'number' => null]);

    expect($invoice->number)->toBe('2027-0001');

    Carbon::setTestNow();
});

test('each company has its own independent numbering sequence', function () {
    Carbon::setTestNow('2026-01-15');
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Invoice::factory()->create(['company_id' => $companyA->id, 'number' => null]);
    $firstForB = Invoice::factory()->create(['company_id' => $companyB->id, 'number' => null]);
    $secondForA = Invoice::factory()->create(['company_id' => $companyA->id, 'number' => null]);

    expect($firstForB->number)->toBe('2026-0001');
    expect($secondForA->number)->toBe('2026-0002');

    Carbon::setTestNow();
});

test('an explicitly provided number is respected instead of being generated', function () {
    $invoice = Invoice::factory()->create(['number' => '2026-9999']);

    expect($invoice->number)->toBe('2026-9999');
});

test('invoice number must be unique per company at the database level', function () {
    $company = Company::factory()->create();
    Invoice::factory()->create(['company_id' => $company->id, 'number' => '2026-0001']);

    expect(fn () => Invoice::factory()->create(['company_id' => $company->id, 'number' => '2026-0001']))
        ->toThrow(QueryException::class);
});

test('the same invoice number can be reused by a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    Invoice::factory()->create(['company_id' => $companyA->id, 'number' => '2026-0001']);

    $invoice = Invoice::factory()->create(['company_id' => $companyB->id, 'number' => '2026-0001']);

    expect($invoice->number)->toBe('2026-0001');
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

test('invoices index only lists invoices for the current company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Invoice::factory()->count(2)->create(['company_id' => $company->id]);
    Invoice::factory()->count(3)->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('invoices.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('invoices.data', 2));
});

test('invoices index renders with an empty state when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('invoices.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('invoices.data', 0));
});

test('invoice create page can be rendered', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('invoices.create'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->has('nextNumber')
    );
});

test('invoice create page redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('invoices.create'));

    $response->assertRedirect(route('companies.create'));
});

test('invoice edit page can be rendered', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('invoices.edit', $invoice));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Edit')
        ->has('invoice')
        ->has('customers')
    );
});

test('viewing the edit page of an invoice from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('invoices.edit', $invoice));

    $response->assertForbidden();
});

test('invoice can be created with rows', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'paid' => false,
        'customer_id' => $customer->id,
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

test('invoice store redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertRedirect(route('companies.create'));
    expect(Invoice::query()->where('customer_id', $customer->id)->exists())->toBeFalse();
});

test('invoice store ignores a company_id sent in the payload and uses the current company instead', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'company_id' => $otherCompany->id,
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $invoice = Invoice::query()->where('customer_id', $customer->id)->firstOrFail();
    expect($invoice->company_id)->toBe($company->id);
});

test('a number already used by another company passes validation', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Invoice::factory()->create(['company_id' => $otherCompany->id, 'number' => '2026-0050']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'number' => '2026-0050',
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('invoices.index'));
});

test('invoice requires at least one row', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'rows' => [],
    ]);

    $response->assertSessionHasErrors('rows');
});

test('invoice row requires a quantity', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'en',
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

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 0, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('rows.0.quantity');
});

test('invoice customer_id must reference an existing customer', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => (string) Str::uuid(),
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('customer_id');
});

test('invoice number can be set explicitly and must be unique', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();
    Invoice::factory()->create(['company_id' => $company->id, 'number' => '2026-0050']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'number' => '2026-0050',
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('number');
});

test('updating an invoice syncs its rows: adds, updates and removes', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $keepRow = InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Keep me',
        'quantity' => 1,
        'price' => 10,
        'vat_rate' => 22,
    ]);
    $removeRow = InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('invoices.update', $invoice), [
        'number' => $invoice->number,
        'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
        'paid' => true,
        'customer_id' => $invoice->customer_id,
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

test('updating an invoice from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('invoices.update', $invoice), [
        'number' => $invoice->number,
        'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
        'paid' => true,
        'customer_id' => $invoice->customer_id,
        'language' => $invoice->language,
        'rows' => [
            ['description' => 'Hacked row', 'quantity' => 1, 'price' => 1, 'vat_rate' => 0],
        ],
    ]);

    $response->assertForbidden();
    expect($invoice->fresh()->paid)->toBeFalse();
});

test('invoice can be deleted and its rows are removed', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->count(2)->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('invoices.destroy', $invoice));

    $response->assertRedirect(route('invoices.index'));
    expect(Invoice::query()->find($invoice->id))->toBeNull();
    expect(InvoiceRow::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('deleting an invoice from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('invoices.destroy', $invoice));

    $response->assertForbidden();
    expect(Invoice::query()->find($invoice->id))->not->toBeNull();
});

test('invoice factory produces a valid language', function () {
    $invoice = Invoice::factory()->create();

    expect(['it', 'en', 'es'])->toContain($invoice->language);
});

test('invoice requires a language', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
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
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
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
    $currentCompany = Company::factory()->create();
    $sourceCompany = Company::factory()->create();
    $source = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'company_id' => $sourceCompany->id,
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

    $response = $this->actingAs($user)->withSession(['current_company_id' => $currentCompany->id])->get(route('invoices.create', ['duplicate' => $source->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate.customer_id', $customer->id)
        ->where('duplicate.note', 'Source note')
        ->where('duplicate.language', 'en')
        ->where('duplicate.rows.0.description', 'Consulting')
        ->where('duplicate.rows.0.quantity', 2)
        ->where('duplicate.rows.0.price', 100)
        ->where('duplicate.rows.0.vat_rate', 22)
        ->missing('duplicate.company_id')
        ->missing('duplicate.paid')
        ->missing('duplicate.rows.0.id')
    );
});

test('invoice create page ignores an invalid duplicate id', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('invoices.create', ['duplicate' => 'not-a-real-id']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate', null)
    );
});

test('invoice create page ignores an array-valued duplicate param', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get('/invoices/create?duplicate[]=x');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate', null)
    );
});

test('a duplicated invoice can be saved as a new invoice', function () {
    $user = User::factory()->create();
    $currentCompany = Company::factory()->create();
    $sourceCompany = Company::factory()->create();
    $source = Invoice::factory()->create(['company_id' => $sourceCompany->id, 'language' => 'en', 'paid' => true]);
    InvoiceRow::factory()->create([
        'invoice_id' => $source->id, 'description' => 'Consulting', 'price' => 100, 'vat_rate' => 22,
    ]);

    $page = $this->actingAs($user)->withSession(['current_company_id' => $currentCompany->id])->get(route('invoices.create', ['duplicate' => $source->id]));
    $duplicate = $page->viewData('page')['props']['duplicate'];

    $this->actingAs($user)->withSession(['current_company_id' => $currentCompany->id])->post(route('invoices.store'), [
        'number' => $duplicate['number'] ?? null,
        'invoice_date' => now()->toDateString(),
        'paid' => false,
        'customer_id' => $duplicate['customer_id'],
        'note' => $duplicate['note'] ?? '',
        'language' => $duplicate['language'],
        'rows' => $duplicate['rows'],
    ])->assertRedirect(route('invoices.index'))->assertSessionHasNoErrors();

    expect(Invoice::count())->toBe(2);
    $new = Invoice::query()->where('id', '!=', $source->id)->firstOrFail();
    expect($new->paid)->toBeFalse()
        ->and($new->rows)->toHaveCount(1)
        ->and($new->company_id)->toBe($currentCompany->id);
    expect($source->fresh()->paid)->toBeTrue(); // source untouched
});

test('invoice create page has no duplicate data without the query param', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('invoices.create'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate', null)
    );
});
```

Note: `invoice create page prefills from a duplicate query param` no longer asserts `duplicate.company_id` — the `Create` page's `nextNumber` prop is not part of `duplicate` at all (it comes from `Invoice::nextNumber()` directly), so `duplicate` never had a `number` key either; the `a duplicated invoice can be saved as a new invoice` test reads `$duplicate['number'] ?? null` defensively but `duplicate` genuinely has no `number` key — pass `null` so the store request lets the server generate one, matching production behavior.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: FAIL — `Invoice::nextNumber()` still takes no required argument, controller still reads/requires `company_id` from the request, no 403 checks yet, unique constraint still global.

- [ ] **Step 3: Update the Invoice model's numbering**

In `app/Models/Invoice.php`, replace:

```php
    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice): void {
            if (! $invoice->number) {
                $invoice->number = static::nextNumber();
            }
        });
    }

    public static function nextNumber(?string $year = null): string
    {
        $year ??= now()->format('Y');

        $lastNumber = static::query()
            ->where('number', 'like', "{$year}-%")
            ->orderByDesc('number')
            ->value('number');

        $sequence = $lastNumber
            ? ((int) substr($lastNumber, strlen($year) + 1)) + 1
            : 1;

        return sprintf('%s-%04d', $year, $sequence);
    }
```

with:

```php
    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice): void {
            if (! $invoice->number) {
                $invoice->number = static::nextNumber($invoice->company_id);
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
```

- [ ] **Step 4: Add the composite unique constraint migration**

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
            $table->dropUnique(['number']);
            $table->unique(['company_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'number']);
            $table->unique(['number']);
        });
    }
};
```

Save as `database/migrations/2026_08_13_120100_make_invoice_number_unique_per_company.php`.

- [ ] **Step 5: Scope the uniqueness validation rules to the current company**

Replace the full contents of `app/Http/Requests/StoreInvoiceRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Support\CurrentCompany;
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
            'number' => [
                'nullable', 'string', 'max:20',
                Rule::unique('invoices', 'number')->where('company_id', CurrentCompany::resolve()?->id),
            ],
            'invoice_date' => ['required', 'date'],
            'paid' => ['boolean'],
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'note' => ['nullable', 'string'],
            'language' => ['required', 'string', Rule::in(['it', 'en', 'es'])],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
```

Replace the full contents of `app/Http/Requests/UpdateInvoiceRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Support\CurrentCompany;
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
                Rule::unique('invoices', 'number')
                    ->where('company_id', CurrentCompany::resolve()?->id)
                    ->ignore($this->route('invoice')),
            ],
            'invoice_date' => ['required', 'date'],
            'paid' => ['boolean'],
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'note' => ['nullable', 'string'],
            'language' => ['required', 'string', Rule::in(['it', 'en', 'es'])],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.id' => ['nullable', 'uuid', 'exists:invoice_rows,id'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
```

- [ ] **Step 6: Update the controller**

Replace the full contents of `app/Http/Controllers/InvoiceController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Support\CurrentCompany;
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
        $currentCompanyId = CurrentCompany::resolve()?->id;

        $invoices = Invoice::query()
            ->with(['customer', 'rows'])
            ->where('company_id', $currentCompanyId)
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

    public function create(Request $request): Response|RedirectResponse
    {
        $currentCompany = CurrentCompany::resolve();

        if ($currentCompany === null) {
            return $this->redirectToCreateCompany();
        }

        $duplicateId = is_string($id = $request->query('duplicate')) ? trim($id) : '';

        $source = $duplicateId !== ''
            ? Invoice::query()->with('rows')->find($duplicateId)
            : null;

        return Inertia::render('invoices/Create', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'nextNumber' => Invoice::nextNumber($currentCompany->id),
            'duplicate' => $source ? [
                'customer_id' => $source->customer_id,
                'note' => $source->note,
                'language' => $source->language,
                'rows' => $source->rows->map(fn (InvoiceRow $row): array => [
                    'description' => $row->description,
                    'quantity' => $row->quantity,
                    'price' => $row->price,
                    'vat_rate' => $row->vat_rate,
                ])->all(),
            ] : null,
        ]);
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        $currentCompany = CurrentCompany::resolve();

        if ($currentCompany === null) {
            return $this->redirectToCreateCompany();
        }

        DB::transaction(function () use ($request, $currentCompany) {
            $invoice = Invoice::query()->create([
                ...$request->safe()->except('rows'),
                'company_id' => $currentCompany->id,
            ]);

            $invoice->rows()->createMany($request->safe()->input('rows'));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice created.')]);

        return to_route('invoices.index');
    }

    public function edit(Invoice $invoice): Response
    {
        $this->authorizeCurrentCompany($invoice);

        return Inertia::render('invoices/Edit', [
            'invoice' => $invoice->load('rows'),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoice);

        DB::transaction(function () use ($request, $invoice) {
            $invoice->update($request->safe()->except('rows'));

            $rows = collect($this->rowsInput($request));
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsInput(UpdateInvoiceRequest $request): array
    {
        return $request->safe()->input('rows');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoice);

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

    private function authorizeCurrentCompany(Invoice $invoice): void
    {
        abort_unless($invoice->company_id === CurrentCompany::resolve()?->id, 403);
    }

    private function redirectToCreateCompany(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('Create a company before you can manage invoices or products.')]);

        return to_route('companies.create');
    }
}
```

- [ ] **Step 7: Add the new flash message translations**

In `resources/lang/it.json`, replace:

```json
    "Product deleted.": "Prodotto eliminato.",
```

with:

```json
    "Product deleted.": "Prodotto eliminato.",
    "Create a company before you can manage invoices or products.": "Crea un'azienda prima di poter gestire fatture o prodotti.",
```

In `resources/lang/es.json`, replace:

```json
    "Product deleted.": "Producto eliminado.",
```

with:

```json
    "Product deleted.": "Producto eliminado.",
    "Create a company before you can manage invoices or products.": "Crea una empresa antes de poder gestionar facturas o productos.",
```

- [ ] **Step 8: Remove the company field from the invoice forms**

In `resources/js/pages/invoices/Create.vue`, replace:

```ts
type Company = {
    id: string;
    name: string;
};

type InvoiceRowForm = {
```

with:

```ts
type InvoiceRowForm = {
```

replace:

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

with:

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

replace:

```ts
    customer_id: props.duplicate?.customer_id ?? '',
    company_id: props.duplicate?.company_id ?? props.defaultCompanyId ?? '',
    note: props.duplicate?.note ?? '',
```

with:

```ts
    customer_id: props.duplicate?.customer_id ?? '',
    note: props.duplicate?.note ?? '',
```

replace:

```vue
            <div class="grid gap-2">
                <Label for="company_id">{{
                    t('invoices.create.company')
                }}</Label>
                <Select v-model="form.company_id">
                    <SelectTrigger id="company_id" class="w-full">
                        <SelectValue
                            :placeholder="t('invoices.create.selectCompany')"
                        />
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

            <div class="grid gap-2">
                <Label for="language">{{
```

with:

```vue
            <div class="grid gap-2">
                <Label for="language">{{
```

In `resources/js/pages/invoices/Edit.vue`, replace:

```ts
type Company = {
    id: string;
    name: string;
};

type InvoiceRow = {
```

with:

```ts
type InvoiceRow = {
```

replace:

```ts
const props = defineProps<{
    invoice: Invoice;
    customers: Customer[];
    companies: Company[];
}>();
```

with:

```ts
const props = defineProps<{
    invoice: Invoice;
    customers: Customer[];
}>();
```

replace:

```ts
    customer_id: props.invoice.customer_id,
    company_id: props.invoice.company_id,
    note: props.invoice.note ?? '',
```

with:

```ts
    customer_id: props.invoice.customer_id,
    note: props.invoice.note ?? '',
```

replace:

```vue
            <div class="grid gap-2">
                <Label for="company_id">{{
                    t('invoices.create.company')
                }}</Label>
                <Select v-model="form.company_id">
                    <SelectTrigger id="company_id" class="w-full">
                        <SelectValue
                            :placeholder="t('invoices.create.selectCompany')"
                        />
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

            <div class="grid gap-2">
                <Label for="language">{{
```

with:

```vue
            <div class="grid gap-2">
                <Label for="language">{{
```

- [ ] **Step 9: Remove the now-unused invoice form translation keys**

In `resources/js/lang/en.ts`, replace:

```ts
            selectCustomer: 'Select a customer',
            company: 'Issuing company',
            selectCompany: 'Select a company',
            language: 'Language',
```

with:

```ts
            selectCustomer: 'Select a customer',
            language: 'Language',
```

In `resources/js/lang/it.ts`, replace:

```ts
            selectCustomer: 'Seleziona un cliente',
            company: 'Azienda emittente',
            selectCompany: "Seleziona un'azienda",
            language: 'Lingua',
```

with:

```ts
            selectCustomer: 'Seleziona un cliente',
            language: 'Lingua',
```

In `resources/js/lang/es.ts`, replace:

```ts
            selectCustomer: 'Selecciona un cliente',
            company: 'Empresa emisora',
            selectCompany: 'Selecciona una empresa',
            language: 'Idioma',
```

with:

```ts
            selectCustomer: 'Selecciona un cliente',
            language: 'Idioma',
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=InvoiceTest`
Expected: PASS

- [ ] **Step 11: Type-check the frontend**

Run: `npm run types:check`
Expected: no new errors (confirms `companies`/`defaultCompanyId`/`company_id` are gone everywhere they were referenced).

- [ ] **Step 12: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_13_120100_make_invoice_number_unique_per_company.php app/Models/Invoice.php app/Http/Controllers/InvoiceController.php app/Http/Requests/StoreInvoiceRequest.php app/Http/Requests/UpdateInvoiceRequest.php resources/js/pages/invoices/Create.vue resources/js/pages/invoices/Edit.vue resources/js/lang/en.ts resources/js/lang/it.ts resources/js/lang/es.ts resources/lang/it.json resources/lang/es.json tests/Feature/InvoiceTest.php
git commit -m "feat: scope invoices to the current company"
```

---

## Task 7: Products become company-scoped

**Files:**
- Modify: `app/Http/Controllers/ProductController.php`
- Modify: `tests/Feature/ProductTest.php`

**Interfaces:**
- Consumes: `App\Support\CurrentCompany::resolve()` (Task 2); the flash message key added in Task 6, Step 7.
- Produces: `index`/JSON picker response filtered by current company; `store` auto-assigns `company_id`; `create`/`store` redirect to `companies.create` when no company exists; `edit`/`update`/`destroy` `abort(403)` on a foreign company's product.

- [ ] **Step 1: Replace the controller-behavior tests**

In `tests/Feature/ProductTest.php`, replace everything from `test('guests are redirected to the login page when visiting products', ...)` to the end of the file with:

```php
test('guests are redirected to the login page when visiting products', function () {
    $this->get(route('products.index'))->assertRedirect(route('login'));
});

test('products index page can be rendered', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Product::factory()->count(3)->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('products.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('products/Index'));
});

test('products index only lists products for the current company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Product::factory()->count(2)->create(['company_id' => $company->id]);
    Product::factory()->count(3)->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('products.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('products.data', 2));
});

test('products index renders with an empty state when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('products.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('products.data', 0));
});

test('products index can be searched as json for the invoice picker', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Product::factory()->create(['company_id' => $company->id, 'code' => 'SKU-1', 'description' => 'Blue widget']);
    Product::factory()->create(['company_id' => $company->id, 'code' => 'SKU-2', 'description' => 'Red widget']);
    Product::factory()->create(['company_id' => $company->id, 'code' => 'SKU-3', 'description' => 'Consulting hour']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->getJson(route('products.index', ['search' => 'widget']));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect(collect($response->json('data'))->pluck('code')->sort()->values()->all())
        ->toBe(['SKU-1', 'SKU-2']);
});

test('the product picker json response does not include products from another company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Product::factory()->create(['company_id' => $company->id, 'code' => 'SKU-1', 'description' => 'Blue widget']);
    Product::factory()->create(['company_id' => $otherCompany->id, 'code' => 'SKU-2', 'description' => 'Blue widget']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->getJson(route('products.index', ['search' => 'widget']));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.code'))->toBe('SKU-1');
});

test('products index json response is paginated', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Product::factory()->count(20)->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->getJson(route('products.index'));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(15);
    expect($response->json('last_page'))->toBe(2);
});

test('product can be created without a code', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'type' => ProductType::Product->value,
        'description' => 'Widget',
        'price' => 9.99,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('products.index'));
    $product = Product::query()->where('description', 'Widget')->firstOrFail();
    expect($product->company_id)->toBe($company->id);
});

test('product create page redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('products.create'));

    $response->assertRedirect(route('companies.create'));
});

test('product store redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('products.store'), [
        'type' => ProductType::Product->value,
        'description' => 'Widget',
        'price' => 9.99,
    ]);

    $response->assertRedirect(route('companies.create'));
    expect(Product::query()->where('description', 'Widget')->exists())->toBeFalse();
});

test('product store ignores a company_id sent in the payload and uses the current company instead', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'company_id' => $otherCompany->id,
        'type' => ProductType::Product->value,
        'description' => 'Widget',
        'price' => 9.99,
    ]);

    $product = Product::query()->where('description', 'Widget')->firstOrFail();
    expect($product->company_id)->toBe($company->id);
});

test('product description is required', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'type' => ProductType::Product->value,
        'description' => '',
        'price' => 9.99,
    ]);

    $response->assertSessionHasErrors('description');
});

test('product type must be a valid enum value', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'type' => 'invalid',
        'description' => 'Widget',
        'price' => 9.99,
    ]);

    $response->assertSessionHasErrors('type');
});

test('product price is required', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'type' => ProductType::Product->value,
        'description' => 'Widget',
        'price' => '',
    ]);

    $response->assertSessionHasErrors('price');
});

test('product code must be unique when present', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Product::factory()->create(['company_id' => $company->id, 'code' => 'SKU-1']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'code' => 'SKU-1',
        'type' => ProductType::Product->value,
        'description' => 'Widget',
        'price' => 9.99,
    ]);

    $response->assertSessionHasErrors('code');
});

test('product can be created with a code', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'code' => 'SKU-2',
        'type' => ProductType::Service->value,
        'description' => 'Consulting',
        'price' => 100,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('products.index'));
    expect(Product::query()->where('code', 'SKU-2')->first()->type)->toBe(ProductType::Service);
});

test('product can be updated', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $company->id, 'description' => 'Old description']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('products.update', $product), [
        'code' => $product->code,
        'type' => $product->type->value,
        'description' => 'New description',
        'price' => $product->price,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('products.index'));
    expect($product->fresh()->description)->toBe('New description');
});

test('product update keeps its own code as valid', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $company->id, 'code' => 'SKU-3']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('products.update', $product), [
        'code' => 'SKU-3',
        'type' => $product->type->value,
        'description' => $product->description,
        'price' => $product->price,
    ]);

    $response->assertSessionHasNoErrors();
});

test('product can be deleted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('products.destroy', $product));

    $response->assertRedirect(route('products.index'));
    expect(Product::query()->find($product->id))->toBeNull();
});

test('viewing the edit page of a product from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('products.edit', $product));

    $response->assertForbidden();
});

test('updating a product from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('products.update', $product), [
        'type' => $product->type->value,
        'description' => 'Hacked description',
        'price' => $product->price,
    ]);

    $response->assertForbidden();
    expect($product->fresh()->description)->not->toBe('Hacked description');
});

test('deleting a product from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('products.destroy', $product));

    $response->assertForbidden();
    expect(Product::query()->find($product->id))->not->toBeNull();
});
```

The `use App\Models\User;` import already present at the top of the file (added in Task 1's edits alongside `Company` and `QueryException`) covers this section too.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=ProductTest`
Expected: FAIL — controller doesn't filter by company, doesn't redirect when no company exists, doesn't 403 on cross-company access.

- [ ] **Step 3: Update the controller**

Replace the full contents of `app/Http/Controllers/ProductController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $search = $request->string('search')->trim()->toString();
        $currentCompanyId = CurrentCompany::resolve()?->id;

        $products = Product::query()
            ->where('company_id', $currentCompanyId)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            }))
            ->orderBy('description')
            ->paginate(15)
            ->withQueryString();

        if ($request->wantsJson()) {
            return response()->json($products);
        }

        return Inertia::render('products/Index', [
            'products' => $products,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        if (CurrentCompany::resolve() === null) {
            return $this->redirectToCreateCompany();
        }

        return Inertia::render('products/Create');
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $currentCompany = CurrentCompany::resolve();

        if ($currentCompany === null) {
            return $this->redirectToCreateCompany();
        }

        Product::query()->create([
            ...$request->validated(),
            'company_id' => $currentCompany->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product created.')]);

        return to_route('products.index');
    }

    public function edit(Product $product): Response
    {
        $this->authorizeCurrentCompany($product);

        return Inertia::render('products/Edit', [
            'product' => $product,
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorizeCurrentCompany($product);

        $product->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product updated.')]);

        return to_route('products.index');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorizeCurrentCompany($product);

        $product->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product deleted.')]);

        return to_route('products.index');
    }

    private function authorizeCurrentCompany(Product $product): void
    {
        abort_unless($product->company_id === CurrentCompany::resolve()?->id, 403);
    }

    private function redirectToCreateCompany(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('Create a company before you can manage invoices or products.')]);

        return to_route('companies.create');
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=ProductTest`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/ProductController.php tests/Feature/ProductTest.php
git commit -m "feat: scope products to the current company"
```

---

## Task 8: Full verification pass

**Files:** none (verification only).

- [ ] **Step 1: Run the full backend test suite**

Run: `php artisan test --compact`
Expected: PASS, no regressions in `CompanyTest`, `CustomerTest`, `CountryTest`, `SetLocaleTest`, `LocalizedValidationTest`, `LocalizedFlashMessageTest`, `InvoiceTemplateTest`, `DashboardTest`, `ExampleTest`, and the files touched above.

- [ ] **Step 2: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: no new errors (in particular, `CurrentCompany::resolve()`'s nullability must be respected everywhere it's called — Larastan will flag any unguarded `->id` access on a possibly-null value).

- [ ] **Step 3: Run the frontend checks**

Run: `npm run types:check`
Run: `npm run lint:check`
Run: `npm run format:check`
Expected: no errors. If `format:check` fails on any file touched above, run `npm run format` and re-stage.

- [ ] **Step 4: Fix anything that surfaced**

If any check above fails, fix the underlying issue (not by weakening the check) and re-run the specific command until clean.

- [ ] **Step 5: Final commit (if Step 4 produced changes)**

```bash
git add -A
git commit -m "chore: fix issues found in full verification pass"
```
