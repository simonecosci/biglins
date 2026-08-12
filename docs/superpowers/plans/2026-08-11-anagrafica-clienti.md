# Anagrafica Clienti Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a customer registry (`Customer`) with a related `Country` lookup table, full CRUD on both, backend + Inertia/Vue frontend.

**Architecture:** Two Eloquent models (`Country`, `Customer`) with UUID primary keys (`HasUuids`), a `belongsTo`/`hasMany` relation between them, resourceful Laravel controllers behind `auth`+`verified` middleware, Pest feature tests, and Inertia v3 Vue pages built from the existing shadcn-vue component set (no new pages of this shape exist yet in the app, so this plan establishes the pattern).

**Tech Stack:** Laravel 13 (PHP 8.5), Inertia Laravel v3, Laravel Wayfinder v0, Pest v5, Vue 3 + Inertia v3, Tailwind v4, reka-ui (shadcn-vue), Laravel Pint.

## Global Constraints

- Always use curly braces for control structures, even single-line bodies.
- Use PHP 8 constructor property promotion where applicable; no empty zero-parameter `__construct()`.
- Explicit return types and param type hints on every method.
- Prefer PHPDoc blocks over inline comments; array shapes in PHPDoc where useful.
- Use `php artisan make:*` commands (with `--no-interaction`) to scaffold new files instead of hand-creating them.
- Do not change application dependencies without approval (no new npm/composer packages — the frontend must use only the already-installed shadcn-vue components: `Input`, `Label`, `Select`, `Button`, `Dialog`).
- Every change must be covered by a Pest test; run only the relevant test file while iterating (`php artisan test --compact tests/Feature/CountryTest.php`), not the full suite.
- After modifying any PHP file: run `vendor/bin/pint --dirty --format agent`.
- After modifying any Vue/TS file: run `npm run types:check` and `npm run build` to catch compile errors (no dev server assumed running).
- This repository is **not** under Git version control (confirmed at plan-writing time) — steps that would normally end in a commit are omitted; each task instead ends once its tests are green.
- UI copy stays in English, matching every existing page in the app (`Profile settings`, `Save`, `Delete account`, …) even though the request was written in Italian — this matches `config/app.php`'s `APP_LOCALE=en` and all existing strings.

---

## File Structure Overview

| File | Responsibility |
|---|---|
| `database/migrations/..._create_countries_table.php` | `countries` schema |
| `database/migrations/..._create_customers_table.php` | `customers` schema, FK to `countries` |
| `app/Models/Country.php` | Country model, `hasMany` customers |
| `app/Models/Customer.php` | Customer model, `belongsTo` country |
| `database/factories/CountryFactory.php` | Test/seed data for Country |
| `database/factories/CustomerFactory.php` | Test/seed data for Customer |
| `database/seeders/CountrySeeder.php` | Bulk-inserts the standard country list |
| `database/seeders/DatabaseSeeder.php` | Modified to call `CountrySeeder` |
| `app/Http/Controllers/CountryController.php` | Resourceful CRUD for countries |
| `app/Http/Controllers/CustomerController.php` | Resourceful CRUD for customers |
| `app/Http/Requests/StoreCountryRequest.php`, `UpdateCountryRequest.php` | Country validation |
| `app/Http/Requests/StoreCustomerRequest.php`, `UpdateCustomerRequest.php` | Customer validation |
| `routes/countries.php`, `routes/customers.php` | Route groups, required from `web.php` |
| `tests/Feature/CountryTest.php` | Model + seeder + CRUD tests for Country |
| `tests/Feature/CustomerTest.php` | Model + CRUD tests for Customer |
| `resources/js/pages/countries/{Index,Create,Edit}.vue` | Country UI |
| `resources/js/pages/customers/{Index,Create,Edit}.vue` | Customer UI |
| `resources/js/components/AppSidebar.vue` | Modified to add "Customers"/"Countries" nav links |

---

### Task 1: Country data layer (migration, model, factory)

**Files:**
- Create: `app/Models/Country.php`
- Create: `database/migrations/{timestamp}_create_countries_table.php`
- Create: `database/factories/CountryFactory.php`
- Test: `tests/Feature/CountryTest.php`

**Interfaces:**
- Produces: `App\Models\Country` — `id` (string uuid, primary key), `name` (string), `customers(): HasMany`. `Database\Factories\CountryFactory` — `definition()` returns `['name' => string]`.

- [ ] **Step 1: Scaffold the model, migration and factory**

```bash
php artisan make:model Country -mf --no-interaction
```

This creates `app/Models/Country.php`, `database/migrations/{timestamp}_create_countries_table.php`, and `database/factories/CountryFactory.php`.

- [ ] **Step 2: Write the migration**

Replace the contents of the generated `database/migrations/{timestamp}_create_countries_table.php`:

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
        Schema::create('countries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
```

- [ ] **Step 3: Write the model**

Replace the contents of `app/Models/Country.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory, HasUuids;

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
```

Note: `Customer` doesn't exist yet — that's fine, it's created in Task 2 before this code path is ever exercised, and PHP resolves the class name lazily at call time, not at parse time.

- [ ] **Step 4: Write the factory**

Replace the contents of `database/factories/CountryFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->country(),
        ];
    }
}
```

- [ ] **Step 5: Write the failing test**

Create `tests/Feature/CountryTest.php`:

```php
<?php

use App\Models\Country;

test('country factory creates a country with a uuid primary key', function () {
    $country = Country::factory()->create();

    expect($country->id)->toBeString();
    expect(strlen($country->id))->toBe(36);
});

test('country name must be unique at the database level', function () {
    Country::factory()->create(['name' => 'Italia']);

    expect(fn () => Country::factory()->create(['name' => 'Italia']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 6: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/CountryTest.php`
Expected: FAIL — `countries` table does not exist yet (migration not run).

- [ ] **Step 7: Run the migration and re-run the test**

Run: `php artisan migrate --no-interaction`
Run: `php artisan test --compact tests/Feature/CountryTest.php`
Expected: PASS (2 tests).

- [ ] **Step 8: Format**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 2: Customer data layer (migration, model, factory)

**Files:**
- Create: `app/Models/Customer.php`
- Create: `database/migrations/{timestamp}_create_customers_table.php`
- Create: `database/factories/CustomerFactory.php`
- Test: `tests/Feature/CustomerTest.php`

**Interfaces:**
- Consumes: `App\Models\Country` (Task 1) — `Country::factory()`.
- Produces: `App\Models\Customer` — `id` (uuid), `name`, `address`, `zip`, `city`, `country_id` (nullable uuid FK), `state`, `email`, `web`, `phone`, `nif` (all nullable except `name`), `country(): BelongsTo`. `Database\Factories\CustomerFactory` — `definition()`.

- [ ] **Step 1: Scaffold the model, migration and factory**

```bash
php artisan make:model Customer -mf --no-interaction
```

- [ ] **Step 2: Write the migration**

Replace the contents of the generated `database/migrations/{timestamp}_create_customers_table.php`:

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
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('zip')->nullable();
            $table->string('city')->nullable();
            $table->foreignUuid('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state')->nullable();
            $table->string('email')->nullable();
            $table->string('web')->nullable();
            $table->string('phone')->nullable();
            $table->string('nif')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
```

- [ ] **Step 3: Write the model**

Replace the contents of `app/Models/Customer.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string|null $address
 * @property string|null $zip
 * @property string|null $city
 * @property string|null $country_id
 * @property string|null $state
 * @property string|null $email
 * @property string|null $web
 * @property string|null $phone
 * @property string|null $nif
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'address', 'zip', 'city', 'country_id', 'state', 'email', 'web', 'phone', 'nif'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
```

- [ ] **Step 4: Write the factory**

Replace the contents of `database/factories/CustomerFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'address' => fake()->streetAddress(),
            'zip' => fake()->postcode(),
            'city' => fake()->city(),
            'country_id' => Country::factory(),
            'state' => fake()->state(),
            'email' => fake()->unique()->companyEmail(),
            'web' => fake()->url(),
            'phone' => fake()->phoneNumber(),
            'nif' => fake()->numerify('########'),
        ];
    }
}
```

- [ ] **Step 5: Write the failing test**

Create `tests/Feature/CustomerTest.php`:

```php
<?php

use App\Models\Country;
use App\Models\Customer;

test('customer factory creates a customer belonging to a country', function () {
    $customer = Customer::factory()->create();

    expect($customer->id)->toBeString();
    expect(strlen($customer->id))->toBe(36);
    expect($customer->country)->toBeInstanceOf(Country::class);
});

test('customer can be created without a country', function () {
    $customer = Customer::factory()->create(['country_id' => null]);

    expect($customer->country_id)->toBeNull();
    expect($customer->country)->toBeNull();
});
```

- [ ] **Step 6: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/CustomerTest.php`
Expected: FAIL — `customers` table does not exist yet.

- [ ] **Step 7: Run the migration and re-run the test**

Run: `php artisan migrate --no-interaction`
Run: `php artisan test --compact tests/Feature/CustomerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 8: Format**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 3: Countries seeder

**Files:**
- Create: `database/seeders/CountrySeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `tests/Feature/CountryTest.php` (append)

**Interfaces:**
- Consumes: `countries` table (Task 1).
- Produces: `Database\Seeders\CountrySeeder::run(): void` — bulk-inserts 195 countries (Italian names).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/CountryTest.php`:

```php
test('seeding the countries table populates the standard country list', function () {
    $this->seed(\Database\Seeders\CountrySeeder::class);

    expect(Country::query()->count())->toBe(195);
    expect(Country::query()->where('name', 'Italia')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/CountryTest.php`
Expected: FAIL — class `Database\Seeders\CountrySeeder` not found.

- [ ] **Step 3: Scaffold the seeder**

```bash
php artisan make:seeder CountrySeeder --no-interaction
```

- [ ] **Step 4: Write the seeder**

Replace the contents of `database/seeders/CountrySeeder.php`:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CountrySeeder extends Seeder
{
    /**
     * Seed the countries table with the standard list of world countries.
     */
    public function run(): void
    {
        $names = [
            'Afghanistan', 'Albania', 'Algeria', 'Andorra', 'Angola',
            'Antigua e Barbuda', 'Arabia Saudita', 'Argentina', 'Armenia', 'Australia',
            'Austria', 'Azerbaigian', 'Bahamas', 'Bahrein', 'Bangladesh',
            'Barbados', 'Belgio', 'Belize', 'Benin', 'Bhutan',
            'Bielorussia', 'Birmania (Myanmar)', 'Bolivia', 'Bosnia ed Erzegovina', 'Botswana',
            'Brasile', 'Brunei', 'Bulgaria', 'Burkina Faso', 'Burundi',
            'Cambogia', 'Camerun', 'Canada', 'Capo Verde', 'Ciad',
            'Cile', 'Cina', 'Cipro', 'Città del Vaticano', 'Colombia',
            'Comore', 'Corea del Nord', 'Corea del Sud', "Costa d'Avorio", 'Costa Rica',
            'Croazia', 'Cuba', 'Danimarca', 'Dominica', 'Ecuador',
            'Egitto', 'El Salvador', 'Emirati Arabi Uniti', 'Eritrea', 'Estonia',
            'Eswatini', 'Etiopia', 'Figi', 'Filippine', 'Finlandia',
            'Francia', 'Gabon', 'Gambia', 'Georgia', 'Germania',
            'Ghana', 'Giamaica', 'Giappone', 'Gibuti', 'Giordania',
            'Grecia', 'Grenada', 'Guatemala', 'Guinea', 'Guinea-Bissau',
            'Guinea Equatoriale', 'Guyana', 'Haiti', 'Honduras', 'India',
            'Indonesia', 'Iran', 'Iraq', 'Irlanda', 'Islanda',
            'Israele', 'Italia', 'Kazakhstan', 'Kenya', 'Kirghizistan',
            'Kiribati', 'Kosovo', 'Kuwait', 'Laos', 'Lesotho',
            'Lettonia', 'Libano', 'Liberia', 'Libia', 'Liechtenstein',
            'Lituania', 'Lussemburgo', 'Macedonia del Nord', 'Madagascar', 'Malawi',
            'Malaysia', 'Maldive', 'Mali', 'Malta', 'Marocco',
            'Isole Marshall', 'Mauritania', 'Mauritius', 'Messico', 'Micronesia',
            'Moldavia', 'Monaco', 'Mongolia', 'Montenegro', 'Mozambico',
            'Namibia', 'Nauru', 'Nepal', 'Nicaragua', 'Niger',
            'Nigeria', 'Norvegia', 'Nuova Zelanda', 'Oman', 'Paesi Bassi',
            'Pakistan', 'Palau', 'Palestina', 'Panama', 'Papua Nuova Guinea',
            'Paraguay', 'Perù', 'Polonia', 'Portogallo', 'Qatar',
            'Regno Unito', 'Repubblica Ceca', 'Repubblica Centrafricana', 'Repubblica del Congo', 'Repubblica Democratica del Congo',
            'Repubblica Dominicana', 'Romania', 'Ruanda', 'Russia', 'Saint Kitts e Nevis',
            'Saint Vincent e Grenadine', 'Saint Lucia', 'Samoa', 'San Marino', 'São Tomé e Príncipe',
            'Senegal', 'Serbia', 'Seychelles', 'Sierra Leone', 'Singapore',
            'Siria', 'Slovacchia', 'Slovenia', 'Somalia', 'Spagna',
            'Sri Lanka', "Stati Uniti d'America", 'Sudafrica', 'Sudan', 'Sudan del Sud',
            'Suriname', 'Svezia', 'Svizzera', 'Tagikistan', 'Tanzania',
            'Thailandia', 'Timor Est', 'Togo', 'Tonga', 'Trinidad e Tobago',
            'Tunisia', 'Turchia', 'Turkmenistan', 'Tuvalu', 'Ucraina',
            'Uganda', 'Ungheria', 'Uruguay', 'Uzbekistan', 'Vanuatu',
            'Venezuela', 'Vietnam', 'Yemen', 'Zambia', 'Zimbabwe',
        ];

        $now = now();

        DB::table('countries')->insert(array_map(
            static fn (string $name): array => [
                'id' => (string) Str::uuid(),
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $names,
        ));
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/CountryTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Wire the seeder into `DatabaseSeeder`**

In `database/seeders/DatabaseSeeder.php:16-24`, add the call at the top of `run()`:

```php
    public function run(): void
    {
        $this->call(CountrySeeder::class);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
```

- [ ] **Step 7: Seed the local database**

Run: `php artisan db:seed --class=CountrySeeder --no-interaction`

- [ ] **Step 8: Format**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 4: Country CRUD backend

**Files:**
- Create: `app/Http/Controllers/CountryController.php`
- Create: `app/Http/Requests/StoreCountryRequest.php`
- Create: `app/Http/Requests/UpdateCountryRequest.php`
- Create: `routes/countries.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/CountryTest.php` (append)

**Interfaces:**
- Consumes: `App\Models\Country` (Task 1).
- Produces: named routes `countries.index`, `countries.create`, `countries.store`, `countries.edit`, `countries.update`, `countries.destroy`. Inertia pages rendered: `countries/Index` (props `countries` paginator, `filters.search`), `countries/Create` (no props), `countries/Edit` (prop `country`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/CountryTest.php`:

```php
use App\Models\User;

test('guests are redirected to the login page when visiting countries', function () {
    $this->get(route('countries.index'))->assertRedirect(route('login'));
});

test('countries index page can be rendered', function () {
    $user = User::factory()->create();
    Country::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('countries.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('countries/Index'));
});

test('country can be created', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('countries.store'), [
        'name' => 'Wakanda',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('countries.index'));
    expect(Country::query()->where('name', 'Wakanda')->exists())->toBeTrue();
});

test('country name is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('countries.store'), [
        'name' => '',
    ]);

    $response->assertSessionHasErrors('name');
});

test('country name must be unique when created via the endpoint', function () {
    $user = User::factory()->create();
    Country::factory()->create(['name' => 'Italia']);

    $response = $this->actingAs($user)->post(route('countries.store'), [
        'name' => 'Italia',
    ]);

    $response->assertSessionHasErrors('name');
});

test('country can be updated', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->put(route('countries.update', $country), [
        'name' => 'New Name',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('countries.index'));
    expect($country->fresh()->name)->toBe('New Name');
});

test('updating a country does not conflict with its own name', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create(['name' => 'Italia']);

    $response = $this->actingAs($user)->put(route('countries.update', $country), [
        'name' => 'Italia',
    ]);

    $response->assertSessionHasNoErrors();
});

test('country can be deleted', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create();

    $response = $this->actingAs($user)->delete(route('countries.destroy', $country));

    $response->assertRedirect(route('countries.index'));
    expect(Country::query()->find($country->id))->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/CountryTest.php`
Expected: FAIL — route `countries.index` (and siblings) not defined.

- [ ] **Step 3: Scaffold the controller and form requests**

```bash
php artisan make:controller CountryController --no-interaction
php artisan make:request StoreCountryRequest --no-interaction
php artisan make:request UpdateCountryRequest --no-interaction
```

- [ ] **Step 4: Write `StoreCountryRequest`**

Replace the contents of `app/Http/Requests/StoreCountryRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCountryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', Rule::unique('countries', 'name')],
        ];
    }
}
```

- [ ] **Step 5: Write `UpdateCountryRequest`**

Replace the contents of `app/Http/Requests/UpdateCountryRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCountryRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('countries', 'name')->ignore($this->route('country')),
            ],
        ];
    }
}
```

- [ ] **Step 6: Write the controller**

Replace the contents of `app/Http/Controllers/CountryController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCountryRequest;
use App\Http\Requests\UpdateCountryRequest;
use App\Models\Country;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CountryController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        $countries = Country::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('countries/Index', [
            'countries' => $countries,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('countries/Create');
    }

    public function store(StoreCountryRequest $request): RedirectResponse
    {
        Country::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Country created.')]);

        return to_route('countries.index');
    }

    public function edit(Country $country): Response
    {
        return Inertia::render('countries/Edit', [
            'country' => $country,
        ]);
    }

    public function update(UpdateCountryRequest $request, Country $country): RedirectResponse
    {
        $country->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Country updated.')]);

        return to_route('countries.index');
    }

    public function destroy(Country $country): RedirectResponse
    {
        $country->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Country deleted.')]);

        return to_route('countries.index');
    }
}
```

- [ ] **Step 7: Create the route file**

Create `routes/countries.php`:

```php
<?php

use App\Http\Controllers\CountryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('countries', CountryController::class)->except('show');
});
```

- [ ] **Step 8: Require the route file from `web.php`**

In `routes/web.php:11` (end of file), change:

```php
require __DIR__.'/settings.php';
```

to:

```php
require __DIR__.'/settings.php';
require __DIR__.'/countries.php';
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/CountryTest.php`
Expected: PASS (10 tests).

- [ ] **Step 10: Format**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 5: Customer CRUD backend

**Files:**
- Create: `app/Http/Controllers/CustomerController.php`
- Create: `app/Http/Requests/StoreCustomerRequest.php`
- Create: `app/Http/Requests/UpdateCustomerRequest.php`
- Create: `routes/customers.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/CustomerTest.php` (append)

**Interfaces:**
- Consumes: `App\Models\Customer`, `App\Models\Country` (Tasks 1–2).
- Produces: named routes `customers.index`, `customers.create`, `customers.store`, `customers.edit`, `customers.update`, `customers.destroy`. Inertia pages rendered: `customers/Index` (props `customers` paginator with eager-loaded `country`, `filters.search`), `customers/Create` (prop `countries: {id, name}[]`), `customers/Edit` (props `customer`, `countries`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/CustomerTest.php`:

```php
use App\Models\User;
use Illuminate\Support\Str;

test('guests are redirected to the login page when visiting customers', function () {
    $this->get(route('customers.index'))->assertRedirect(route('login'));
});

test('customers index page can be rendered', function () {
    $user = User::factory()->create();
    Customer::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('customers.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('customers/Index'));
});

test('customer can be created with only a name', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('customers.store'), [
        'name' => 'Acme Corp',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('customers.index'));
    expect(Customer::query()->where('name', 'Acme Corp')->exists())->toBeTrue();
});

test('customer name is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('customers.store'), [
        'name' => '',
    ]);

    $response->assertSessionHasErrors('name');
});

test('customer email must be a valid address when present', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('customers.store'), [
        'name' => 'Acme Corp',
        'email' => 'not-an-email',
    ]);

    $response->assertSessionHasErrors('email');
});

test('customer web must be a valid url when present', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('customers.store'), [
        'name' => 'Acme Corp',
        'web' => 'not-a-url',
    ]);

    $response->assertSessionHasErrors('web');
});

test('customer country_id must reference an existing country', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('customers.store'), [
        'name' => 'Acme Corp',
        'country_id' => (string) Str::uuid(),
    ]);

    $response->assertSessionHasErrors('country_id');
});

test('customer can be created with a valid country', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create();

    $response = $this->actingAs($user)->post(route('customers.store'), [
        'name' => 'Acme Corp',
        'country_id' => $country->id,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('customers.index'));
    expect(Customer::query()->where('name', 'Acme Corp')->first()->country_id)->toBe($country->id);
});

test('customer can be updated', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->put(route('customers.update', $customer), [
        'name' => 'New Name',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('customers.index'));
    expect($customer->fresh()->name)->toBe('New Name');
});

test('customer can be deleted', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();

    $response = $this->actingAs($user)->delete(route('customers.destroy', $customer));

    $response->assertRedirect(route('customers.index'));
    expect(Customer::query()->find($customer->id))->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/CustomerTest.php`
Expected: FAIL — route `customers.index` (and siblings) not defined.

- [ ] **Step 3: Scaffold the controller and form requests**

```bash
php artisan make:controller CustomerController --no-interaction
php artisan make:request StoreCustomerRequest --no-interaction
php artisan make:request UpdateCustomerRequest --no-interaction
```

- [ ] **Step 4: Write `StoreCustomerRequest`**

Replace the contents of `app/Http/Requests/StoreCustomerRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
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
            'address' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country_id' => ['nullable', 'uuid', 'exists:countries,id'],
            'state' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'web' => ['nullable', 'url', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'nif' => ['nullable', 'string', 'max:50'],
        ];
    }
}
```

- [ ] **Step 5: Write `UpdateCustomerRequest`**

Replace the contents of `app/Http/Requests/UpdateCustomerRequest.php` (same rules as store — no field is unique per-record):

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
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
            'address' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country_id' => ['nullable', 'uuid', 'exists:countries,id'],
            'state' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'web' => ['nullable', 'url', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'nif' => ['nullable', 'string', 'max:50'],
        ];
    }
}
```

- [ ] **Step 6: Write the controller**

Replace the contents of `app/Http/Controllers/CustomerController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Country;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        $customers = Customer::query()
            ->with('country')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('customers/Index', [
            'customers' => $customers,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('customers/Create', [
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        Customer::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer created.')]);

        return to_route('customers.index');
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('customers/Edit', [
            'customer' => $customer,
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer updated.')]);

        return to_route('customers.index');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer deleted.')]);

        return to_route('customers.index');
    }
}
```

- [ ] **Step 7: Create the route file**

Create `routes/customers.php`:

```php
<?php

use App\Http\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('customers', CustomerController::class)->except('show');
});
```

- [ ] **Step 8: Require the route file from `web.php`**

In `routes/web.php`, change:

```php
require __DIR__.'/settings.php';
require __DIR__.'/countries.php';
```

to:

```php
require __DIR__.'/settings.php';
require __DIR__.'/countries.php';
require __DIR__.'/customers.php';
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/CustomerTest.php`
Expected: PASS (12 tests).

- [ ] **Step 10: Format**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 6: Country frontend pages

**Files:**
- Create: `resources/js/pages/countries/Index.vue`
- Create: `resources/js/pages/countries/Create.vue`
- Create: `resources/js/pages/countries/Edit.vue`
- Modify: `resources/js/components/AppSidebar.vue`

**Interfaces:**
- Consumes: props from `CountryController` (Task 4) — `countries: {data: {id: string, name: string}[], links: {url: string|null, label: string, active: boolean}[]}`, `filters: {search: string}` on Index; `country: {id: string, name: string}` on Edit.
- Consumes: generated Wayfinder modules `@/actions/App/Http/Controllers/CountryController` and `@/routes/countries` (produced by Step 1 below).

- [ ] **Step 1: Generate Wayfinder actions/routes**

Run: `php artisan wayfinder:generate --no-interaction`

This reads the routes registered in Task 4 and writes `resources/js/actions/App/Http/Controllers/CountryController.ts` and `resources/js/routes/countries/index.ts` (re-exported from `resources/js/routes/countries.ts`).

- [ ] **Step 2: Write the index page**

Create `resources/js/pages/countries/Index.vue`:

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, edit, index } from '@/routes/countries';
import type { BreadcrumbItem } from '@/types';

type Country = {
    id: string;
    name: string;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const props = defineProps<{
    countries: {
        data: Country[];
        links: PaginationLink[];
    };
    filters: {
        search: string;
    };
}>();

const search = ref(props.filters.search);

function onSearch(): void {
    router.get(index().url, { search: search.value }, { preserveState: true, replace: true });
}

defineOptions({
    layout: () => ({
        breadcrumbs: [{ title: 'Countries', href: index() }] satisfies BreadcrumbItem[],
    }),
});
</script>

<template>
    <Head title="Countries" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                title="Countries"
                description="Manage the countries available to customers"
            />
            <Button as-child>
                <Link :href="create()">
                    <Plus />
                    New country
                </Link>
            </Button>
        </div>

        <form class="max-w-sm" @submit.prevent="onSearch">
            <Input v-model="search" placeholder="Search countries..." />
        </form>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="country in countries.data"
                        :key="country.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">{{ country.name }}</td>
                        <td class="px-4 py-2 text-right">
                            <Link
                                :href="edit(country.id)"
                                class="text-primary underline-offset-4 hover:underline"
                            >
                                Edit
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="countries.data.length === 0">
                        <td
                            colspan="2"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No countries found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="countries.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in countries.links"
                :key="i"
                :href="link.url ?? ''"
                :class="[
                    'rounded-md px-3 py-1 text-sm',
                    link.active
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-accent',
                    !link.url && 'pointer-events-none opacity-50',
                ]"
                v-html="link.label"
            />
        </div>
    </div>
</template>
```

- [ ] **Step 3: Write the create page**

Create `resources/js/pages/countries/Create.vue`:

```vue
<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import CountryController from '@/actions/App/Http/Controllers/CountryController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/countries';
import type { BreadcrumbItem } from '@/types';

defineOptions({
    layout: () => ({
        breadcrumbs: [{ title: 'Countries', href: index() }] satisfies BreadcrumbItem[],
    }),
});
</script>

<template>
    <Head title="New country" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            title="New country"
            description="Add a country to the list available to customers"
        />

        <Form
            v-bind="CountryController.store.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    autofocus
                    placeholder="Country name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" type="submit">Save</Button>
                <Link
                    :href="index()"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    Cancel
                </Link>
            </div>
        </Form>
    </div>
</template>
```

- [ ] **Step 4: Write the edit page**

Create `resources/js/pages/countries/Edit.vue`:

```vue
<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import CountryController from '@/actions/App/Http/Controllers/CountryController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/countries';
import type { BreadcrumbItem } from '@/types';

type Country = {
    id: string;
    name: string;
};

const props = defineProps<{
    country: Country;
}>();

defineOptions({
    layout: () => ({
        breadcrumbs: [{ title: 'Countries', href: index() }] satisfies BreadcrumbItem[],
    }),
});

function onDelete(): void {
    if (confirm('Delete this country? This cannot be undone.')) {
        router.delete(CountryController.destroy(props.country.id).url);
    }
}
</script>

<template>
    <Head title="Edit country" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading title="Edit country" :description="`Update ${country.name}`" />

        <Form
            v-bind="CountryController.update.form(country.id)"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="country.name"
                    required
                    autofocus
                    placeholder="Country name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" type="submit">Save</Button>
                <Link
                    :href="index()"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    Cancel
                </Link>
            </div>
        </Form>

        <div class="border-t pt-6">
            <Button variant="destructive" type="button" @click="onDelete">
                Delete country
            </Button>
        </div>
    </div>
</template>
```

- [ ] **Step 5: Add the "Countries" nav link**

In `resources/js/components/AppSidebar.vue:3` and `:18-29`, change:

```ts
import { BookOpen, FolderGit2, LayoutGrid } from '@lucide/vue';
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
import type { NavItem } from '@/types';

const dashboardUrl = computed(() => dashboard().url);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
]);
```

to:

```ts
import { BookOpen, FolderGit2, Globe, LayoutGrid } from '@lucide/vue';
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
import { index as countriesIndex } from '@/routes/countries';
import type { NavItem } from '@/types';

const dashboardUrl = computed(() => dashboard().url);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
    {
        title: 'Countries',
        href: countriesIndex().url,
        icon: Globe,
    },
]);
```

(Task 7 adds the "Customers" entry above "Countries" — both edits touch the same block, so whichever task runs second edits this file again.)

- [ ] **Step 6: Verify compile and existing tests**

Run: `npm run types:check`
Run: `npm run build`
Run: `php artisan test --compact tests/Feature/CountryTest.php`
Expected: all green — the backend tests already covered the Inertia responses; this step only confirms the new Vue files compile and the nav edit didn't break anything.

---

### Task 7: Customer frontend pages

**Files:**
- Create: `resources/js/pages/customers/Index.vue`
- Create: `resources/js/pages/customers/Create.vue`
- Create: `resources/js/pages/customers/Edit.vue`
- Modify: `resources/js/components/AppSidebar.vue`

**Interfaces:**
- Consumes: props from `CustomerController` (Task 5) — `customers: {data: {id, name, city, email, country: {id, name}|null}[], links: PaginationLink[]}`, `filters: {search: string}` on Index; `countries: {id: string, name: string}[]` on Create/Edit; `customer` (all `Customer` fields) on Edit.
- Consumes: generated Wayfinder modules `@/actions/App/Http/Controllers/CustomerController` and `@/routes/customers` (produced by Step 1 below).
- Consumes: `Select`, `SelectTrigger`, `SelectValue`, `SelectContent`, `SelectItem` from `@/components/ui/select` (already installed).

- [ ] **Step 1: Generate Wayfinder actions/routes**

Run: `php artisan wayfinder:generate --no-interaction`

- [ ] **Step 2: Write the index page**

Create `resources/js/pages/customers/Index.vue`:

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, edit, index } from '@/routes/customers';
import type { BreadcrumbItem } from '@/types';

type Customer = {
    id: string;
    name: string;
    city: string | null;
    email: string | null;
    country: { id: string; name: string } | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const props = defineProps<{
    customers: {
        data: Customer[];
        links: PaginationLink[];
    };
    filters: {
        search: string;
    };
}>();

const search = ref(props.filters.search);

function onSearch(): void {
    router.get(index().url, { search: search.value }, { preserveState: true, replace: true });
}

defineOptions({
    layout: () => ({
        breadcrumbs: [{ title: 'Customers', href: index() }] satisfies BreadcrumbItem[],
    }),
});
</script>

<template>
    <Head title="Customers" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading title="Customers" description="Manage your customer registry" />
            <Button as-child>
                <Link :href="create()">
                    <Plus />
                    New customer
                </Link>
            </Button>
        </div>

        <form class="max-w-sm" @submit.prevent="onSearch">
            <Input v-model="search" placeholder="Search by name or email..." />
        </form>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">City</th>
                        <th class="px-4 py-2 font-medium">Country</th>
                        <th class="px-4 py-2 font-medium">Email</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="customer in customers.data"
                        :key="customer.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">{{ customer.name }}</td>
                        <td class="px-4 py-2">{{ customer.city ?? '—' }}</td>
                        <td class="px-4 py-2">{{ customer.country?.name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ customer.email ?? '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            <Link
                                :href="edit(customer.id)"
                                class="text-primary underline-offset-4 hover:underline"
                            >
                                Edit
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="customers.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No customers found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="customers.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in customers.links"
                :key="i"
                :href="link.url ?? ''"
                :class="[
                    'rounded-md px-3 py-1 text-sm',
                    link.active
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-accent',
                    !link.url && 'pointer-events-none opacity-50',
                ]"
                v-html="link.label"
            />
        </div>
    </div>
</template>
```

- [ ] **Step 3: Write the create page**

Create `resources/js/pages/customers/Create.vue`:

```vue
<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
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
import { index } from '@/routes/customers';
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
        breadcrumbs: [{ title: 'Customers', href: index() }] satisfies BreadcrumbItem[],
    }),
});
</script>

<template>
    <Head title="New customer" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading title="New customer" description="Add a customer to the registry" />

        <Form
            v-bind="CustomerController.store.form()"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" required autofocus placeholder="Customer name" />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="address">Address</Label>
                <Input id="address" name="address" placeholder="Address" />
                <InputError :message="errors.address" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="zip">ZIP</Label>
                    <Input id="zip" name="zip" placeholder="ZIP code" />
                    <InputError :message="errors.zip" />
                </div>
                <div class="grid gap-2">
                    <Label for="city">City</Label>
                    <Input id="city" name="city" placeholder="City" />
                    <InputError :message="errors.city" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="country_id">Country</Label>
                    <Select name="country_id">
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
                    <InputError :message="errors.country_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="state">State / Province</Label>
                    <Input id="state" name="state" placeholder="State or province" />
                    <InputError :message="errors.state" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input id="email" type="email" name="email" placeholder="Email address" />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="web">Website</Label>
                <Input id="web" name="web" placeholder="https://example.com" />
                <InputError :message="errors.web" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input id="phone" name="phone" placeholder="Phone number" />
                    <InputError :message="errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="nif">NIF</Label>
                    <Input id="nif" name="nif" placeholder="Tax identification number" />
                    <InputError :message="errors.nif" />
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button :disabled="processing" type="submit">Save</Button>
                <Link
                    :href="index()"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    Cancel
                </Link>
            </div>
        </Form>
    </div>
</template>
```

- [ ] **Step 4: Write the edit page**

Create `resources/js/pages/customers/Edit.vue`:

```vue
<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
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
import { index } from '@/routes/customers';
import type { BreadcrumbItem } from '@/types';

type Country = {
    id: string;
    name: string;
};

type Customer = {
    id: string;
    name: string;
    address: string | null;
    zip: string | null;
    city: string | null;
    country_id: string | null;
    state: string | null;
    email: string | null;
    web: string | null;
    phone: string | null;
    nif: string | null;
};

const props = defineProps<{
    customer: Customer;
    countries: Country[];
}>();

defineOptions({
    layout: () => ({
        breadcrumbs: [{ title: 'Customers', href: index() }] satisfies BreadcrumbItem[],
    }),
});

function onDelete(): void {
    if (confirm('Delete this customer? This cannot be undone.')) {
        router.delete(CustomerController.destroy(props.customer.id).url);
    }
}
</script>

<template>
    <Head title="Edit customer" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading title="Edit customer" :description="`Update ${customer.name}`" />

        <Form
            v-bind="CustomerController.update.form(customer.id)"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="customer.name"
                    required
                    autofocus
                    placeholder="Customer name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="address">Address</Label>
                <Input
                    id="address"
                    name="address"
                    :default-value="customer.address ?? undefined"
                    placeholder="Address"
                />
                <InputError :message="errors.address" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="zip">ZIP</Label>
                    <Input
                        id="zip"
                        name="zip"
                        :default-value="customer.zip ?? undefined"
                        placeholder="ZIP code"
                    />
                    <InputError :message="errors.zip" />
                </div>
                <div class="grid gap-2">
                    <Label for="city">City</Label>
                    <Input
                        id="city"
                        name="city"
                        :default-value="customer.city ?? undefined"
                        placeholder="City"
                    />
                    <InputError :message="errors.city" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="country_id">Country</Label>
                    <Select
                        name="country_id"
                        :default-value="customer.country_id ?? undefined"
                    >
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
                    <InputError :message="errors.country_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="state">State / Province</Label>
                    <Input
                        id="state"
                        name="state"
                        :default-value="customer.state ?? undefined"
                        placeholder="State or province"
                    />
                    <InputError :message="errors.state" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    :default-value="customer.email ?? undefined"
                    placeholder="Email address"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="web">Website</Label>
                <Input
                    id="web"
                    name="web"
                    :default-value="customer.web ?? undefined"
                    placeholder="https://example.com"
                />
                <InputError :message="errors.web" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input
                        id="phone"
                        name="phone"
                        :default-value="customer.phone ?? undefined"
                        placeholder="Phone number"
                    />
                    <InputError :message="errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="nif">NIF</Label>
                    <Input
                        id="nif"
                        name="nif"
                        :default-value="customer.nif ?? undefined"
                        placeholder="Tax identification number"
                    />
                    <InputError :message="errors.nif" />
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button :disabled="processing" type="submit">Save</Button>
                <Link
                    :href="index()"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    Cancel
                </Link>
            </div>
        </Form>

        <div class="border-t pt-6">
            <Button variant="destructive" type="button" @click="onDelete">
                Delete customer
            </Button>
        </div>
    </div>
</template>
```

- [ ] **Step 5: Add the "Customers" nav link**

In `resources/js/components/AppSidebar.vue`, extend the same block Task 6 edited. The final state of the import and computed block should be:

```ts
import { BookOpen, FolderGit2, Globe, LayoutGrid, Users } from '@lucide/vue';
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
import { index as countriesIndex } from '@/routes/countries';
import { index as customersIndex } from '@/routes/customers';
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
        title: 'Countries',
        href: countriesIndex().url,
        icon: Globe,
    },
]);
```

- [ ] **Step 6: Verify compile and full backend suite**

Run: `npm run types:check`
Run: `npm run build`
Run: `php artisan test --compact tests/Feature/CustomerTest.php tests/Feature/CountryTest.php`
Expected: all green.

- [ ] **Step 7: Manual smoke test**

Run: `composer run dev` (starts the Vite dev server + Laravel), then in a browser: log in, open "Customers" from the sidebar, create a customer with a country selected, edit it, delete it. Repeat for "Countries". Confirm the search box filters results and pagination links appear once more than 15 records exist.

---

## Plan Self-Review Notes

- **Spec coverage:** all 11 `customers` fields (Task 2), the `countries` FK relation (Tasks 1–2), the seeder (Task 3), full CRUD backend for both resources (Tasks 4–5), full CRUD frontend with nav entries (Tasks 6–7) are each covered by a task.
- **Type consistency:** `Country` shape (`{id, name}`) and `Customer` shape are identical across every Vue file that consumes them; controller `.get(['id', 'name'])` projections match the TS types exactly; form request field lists match the model's `#[Fillable(...)]` list and the migration columns 1:1.
- **No placeholders:** every step has runnable code; the country list is the full 195-name array, not a stub.
