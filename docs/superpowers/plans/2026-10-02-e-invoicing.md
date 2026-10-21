# Electronic Invoicing (Italy SDI + Spain VERI*FACTU) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each company issue its invoices to its country's tax authority (Italy SDI, Spain VERI*FACTU) through a provider driver it configures, with draft/issued lifecycle, numbering at issue time and submission tracking.

**Architecture:** A `Driver` pattern under `App\EInvoicing`: one `EInvoicingProvider` contract, a `FakeProvider` for tests/local and a `B2BrouterProvider`. Country-specific rules live behind `CountryComplianceRules` (Italy, Spain, Default) resolved by `country.iso_code`. `App\Actions\IssueInvoice` locks and numbers the invoice and creates an `InvoiceSubmission`; `App\Jobs\SubmitInvoice` sends it; webhooks and a polling command update the submission through a single `SubmissionResultRecorder`.

**Tech Stack:** Laravel 13, PHP 8.5, Inertia v3 + Vue 3 + Tailwind v4, Wayfinder, Pest 5, SQLite in tests, `Http::fake()` for the provider.

**Spec:** `docs/superpowers/specs/2026-10-02-e-invoicing-design.md`

## Global Constraints

- All class, enum, column and tool names in English.
- Branch: `feature/e-invoicing` (already created from `develop`).
- No new Composer/npm dependencies.
- Every PHP change: run `vendor/bin/pint --dirty --format agent` before committing.
- Tests: `php artisan test --compact <file or --filter>`; every task adds/updates Pest tests.
- Every user-facing string goes through i18n: PHP `__()` keys added to `resources/lang/it.json` and `resources/lang/es.json` (English is the key itself); Vue strings added to `resources/js/lang/{en,it,es}.ts`.
- After adding/changing routes or controllers run `php artisan wayfinder:generate` (the Vite plugin also does it in dev).
- PA (public administration) for Italy, passive invoices, legal archiving, Spanish simplified invoices (F2), `FatturaPaBuilder` are **out of scope**.
- `Fake` driver only available when `app()->environment(['local', 'testing'])`.
- Credentials are **never** sent to the frontend.
- Desktop build (NativePHP) detection: `(bool) config('nativephp-internal.running')`.

## Decisions taken while planning (not in the spec)

- The invoice `number` is no longer user-editable: removed from the create/edit forms and from `StoreInvoiceRequest`/`UpdateInvoiceRequest`. It is assigned only by `IssueInvoice` (the factory may still set it for tests).
- "Retry" after a `Failed` submission is the same action as "Issue": a failed invoice is back in `Draft`, so issuing it again creates a new submission while keeping its number.
- An invoice is locked iff `status === Issued`. Returning to `Draft` after `Failed`/`Rejected` is decided by `CountryComplianceRules::allowsRevisionAfter()` when the result is recorded.
- Transient provider errors are signalled by `App\EInvoicing\Exceptions\TransientProviderException`; definitive errors are returned as a `SubmissionResult` with status `Failed`.
- All submission updates go through `App\EInvoicing\SubmissionResultRecorder` (job, webhook, polling, manual refresh).
- Fiscal-detail field lists for the Vue forms live in `resources/js/lib/fiscalFields.ts`, mirroring the backend rules.
- The e-invoicing settings live on their own page `companies/{company}/e-invoicing`, linked from the company edit page ("tab").
- In-app notifications for `Rejected`/`NotDelivered`/`Failed`: toast after "Issue" when the synchronous result is `Failed`, plus a persistent alert on the invoice edit page for the latest submission in those states.

## Review Focus

1. **Draft without a number everywhere a number is printed** (PDF filename, preview title, index, dashboard, email subject, MCP list) — must not crash or produce `.pdf`; use a "Draft" label. Test in Task 6.
2. **Issuing twice / double click** — second issue of an already `Issued` invoice must be rejected without creating a second submission or a second number. Test in Task 7 and Task 9.
3. **Webhook for a submission of a different integration** (forged `external_id`) — must be ignored, not update another company's invoice. Test in Task 10.
4. **Updating credentials with blank fields** — must keep existing secrets, and switching driver must not keep stale keys of the old driver. Test in Task 13.
5. **Customer/company with no country or unknown `iso_code`** — resolver falls back to `DefaultComplianceRules`, forms don't crash, issue just locks. Test in Task 4 and Task 7.

---

## File Structure

```
app/Support/CountryIsoCodes.php                         name → ISO 3166-1 alpha-2 map (migration + seeder)
app/Enums/InvoiceStatus.php                             Draft, Issued
app/Actions/IssueInvoice.php                            issue workflow
app/Jobs/SubmitInvoice.php                              send to provider
app/Console/Commands/RefreshEInvoicingStatuses.php      einvoicing:refresh-statuses
app/EInvoicing/Contracts/EInvoicingProvider.php
app/EInvoicing/Contracts/CountryComplianceRules.php
app/EInvoicing/Data/SubmissionResult.php
app/EInvoicing/Data/ProviderNotification.php
app/EInvoicing/Enums/{EInvoicingDriver,EInvoicingEnvironment,SubmissionStatus,Capability}.php
app/EInvoicing/Exceptions/TransientProviderException.php
app/EInvoicing/Providers/FakeProvider.php
app/EInvoicing/Providers/B2BrouterProvider.php
app/EInvoicing/Providers/B2Brouter/InvoicePayloadMapper.php
app/EInvoicing/Compliance/{Italy,Spain,Default}ComplianceRules.php
app/EInvoicing/EInvoicingProviderFactory.php
app/EInvoicing/CountryComplianceResolver.php
app/EInvoicing/SubmissionResultRecorder.php
app/Models/{EInvoicingIntegration,InvoiceSubmission,InvoiceSubmissionEvent}.php
app/Http/Controllers/EInvoicingIntegrationController.php
app/Http/Controllers/EInvoicingWebhookController.php
app/Http/Controllers/InvoiceSubmissionController.php      issue + refresh
app/Http/Requests/UpdateEInvoicingIntegrationRequest.php
app/Mcp/Tools/IssueInvoiceTool.php
routes/einvoicing.php
resources/js/pages/companies/EInvoicing.vue
resources/js/components/FiscalDetailsFields.vue
resources/js/components/VatExemptionSelect.vue
resources/js/components/InvoiceSubmissionPanel.vue
resources/js/lib/fiscalFields.ts
resources/js/lib/invoiceStatus.ts
```

---

## Phase 1 — Fiscal data

### Task 1: `Country.iso_code`

**Files:**
- Create: `app/Support/CountryIsoCodes.php`
- Create: migration `add_iso_code_to_countries_table`
- Modify: `app/Models/Country.php`, `database/factories/CountryFactory.php`, `database/seeders/CountrySeeder.php`
- Modify: `app/Http/Requests/StoreCountryRequest.php`, `app/Http/Requests/UpdateCountryRequest.php`
- Modify: `resources/js/pages/countries/{Create,Edit,Index}.vue`, `resources/js/lang/{en,it,es}.ts`
- Test: `tests/Feature/CountryTest.php`

**Interfaces:**
- Produces: `countries.iso_code` (`char(2)`, nullable, unique); `Country::$iso_code` (`?string`); `CountryFactory::italy()`, `CountryFactory::spain()` states; `CountryIsoCodes::BY_NAME` (`array<string, string>`), `CountryIsoCodes::forName(string $name): ?string`.

- [ ] **Step 1: Write the failing tests** (append to `tests/Feature/CountryTest.php`, reuse the file's existing auth helper pattern — check the top of the file for how a user is created and acting as)

```php
use App\Support\CountryIsoCodes;

test('country iso codes map known names', function () {
    expect(CountryIsoCodes::forName('Italy'))->toBe('IT');
    expect(CountryIsoCodes::forName('Spain'))->toBe('ES');
    expect(CountryIsoCodes::forName('Narnia'))->toBeNull();
});

test('a country can be stored with an iso code', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('countries.store'), ['name' => 'Testland', 'iso_code' => 'tl'])
        ->assertRedirect(route('countries.index'));

    expect(Country::query()->where('name', 'Testland')->value('iso_code'))->toBe('TL');
});

test('country iso code must be two letters and unique', function () {
    Country::factory()->create(['iso_code' => 'IT']);

    $this->actingAs(User::factory()->create())
        ->post(route('countries.store'), ['name' => 'Other', 'iso_code' => 'IT'])
        ->assertSessionHasErrors('iso_code');

    $this->actingAs(User::factory()->create())
        ->post(route('countries.store'), ['name' => 'Other', 'iso_code' => 'ITA'])
        ->assertSessionHasErrors('iso_code');
});

test('the country seeder fills iso codes', function () {
    $this->seed(\Database\Seeders\CountrySeeder::class);

    expect(Country::query()->where('name', 'Italy')->value('iso_code'))->toBe('IT');
    expect(Country::query()->whereNull('iso_code')->count())->toBe(0);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/CountryTest.php`
Expected: FAIL (`CountryIsoCodes` not found / no `iso_code` column).

- [ ] **Step 3: Create `app/Support/CountryIsoCodes.php`**

```php
<?php

namespace App\Support;

/**
 * ISO 3166-1 alpha-2 codes for the country names shipped by CountrySeeder.
 */
class CountryIsoCodes
{
    /**
     * @var array<string, string>
     */
    public const BY_NAME = [
        'Afghanistan' => 'AF', 'Albania' => 'AL', 'Algeria' => 'DZ', 'Andorra' => 'AD', 'Angola' => 'AO',
        'Antigua and Barbuda' => 'AG', 'Argentina' => 'AR', 'Armenia' => 'AM', 'Australia' => 'AU', 'Austria' => 'AT',
        'Azerbaijan' => 'AZ', 'Bahamas' => 'BS', 'Bahrain' => 'BH', 'Bangladesh' => 'BD', 'Barbados' => 'BB',
        'Belarus' => 'BY', 'Belgium' => 'BE', 'Belize' => 'BZ', 'Benin' => 'BJ', 'Bhutan' => 'BT',
        'Bolivia' => 'BO', 'Bosnia and Herzegovina' => 'BA', 'Botswana' => 'BW', 'Brazil' => 'BR', 'Brunei' => 'BN',
        'Bulgaria' => 'BG', 'Burkina Faso' => 'BF', 'Burundi' => 'BI', 'Cambodia' => 'KH', 'Cameroon' => 'CM',
        'Canada' => 'CA', 'Cape Verde' => 'CV', 'Central African Republic' => 'CF', 'Chad' => 'TD', 'Chile' => 'CL',
        'China' => 'CN', 'Colombia' => 'CO', 'Comoros' => 'KM', 'Costa Rica' => 'CR', 'Croatia' => 'HR',
        'Cuba' => 'CU', 'Cyprus' => 'CY', 'Czech Republic' => 'CZ', 'Democratic Republic of the Congo' => 'CD', 'Denmark' => 'DK',
        'Djibouti' => 'DJ', 'Dominica' => 'DM', 'Dominican Republic' => 'DO', 'East Timor' => 'TL', 'Ecuador' => 'EC',
        'Egypt' => 'EG', 'El Salvador' => 'SV', 'Equatorial Guinea' => 'GQ', 'Eritrea' => 'ER', 'Estonia' => 'EE',
        'Eswatini' => 'SZ', 'Ethiopia' => 'ET', 'Fiji' => 'FJ', 'Finland' => 'FI', 'France' => 'FR',
        'Gabon' => 'GA', 'Gambia' => 'GM', 'Georgia' => 'GE', 'Germany' => 'DE', 'Ghana' => 'GH',
        'Greece' => 'GR', 'Grenada' => 'GD', 'Guatemala' => 'GT', 'Guinea' => 'GN', 'Guinea-Bissau' => 'GW',
        'Guyana' => 'GY', 'Haiti' => 'HT', 'Honduras' => 'HN', 'Hungary' => 'HU', 'Iceland' => 'IS',
        'India' => 'IN', 'Indonesia' => 'ID', 'Iran' => 'IR', 'Iraq' => 'IQ', 'Ireland' => 'IE',
        'Israel' => 'IL', 'Italy' => 'IT', 'Ivory Coast' => 'CI', 'Jamaica' => 'JM', 'Japan' => 'JP',
        'Jordan' => 'JO', 'Kazakhstan' => 'KZ', 'Kenya' => 'KE', 'Kiribati' => 'KI', 'Kosovo' => 'XK',
        'Kuwait' => 'KW', 'Kyrgyzstan' => 'KG', 'Laos' => 'LA', 'Latvia' => 'LV', 'Lebanon' => 'LB',
        'Lesotho' => 'LS', 'Liberia' => 'LR', 'Libya' => 'LY', 'Liechtenstein' => 'LI', 'Lithuania' => 'LT',
        'Luxembourg' => 'LU', 'Madagascar' => 'MG', 'Malawi' => 'MW', 'Malaysia' => 'MY', 'Maldives' => 'MV',
        'Mali' => 'ML', 'Malta' => 'MT', 'Marshall Islands' => 'MH', 'Mauritania' => 'MR', 'Mauritius' => 'MU',
        'Mexico' => 'MX', 'Micronesia' => 'FM', 'Moldova' => 'MD', 'Monaco' => 'MC', 'Mongolia' => 'MN',
        'Montenegro' => 'ME', 'Morocco' => 'MA', 'Mozambique' => 'MZ', 'Myanmar (Burma)' => 'MM', 'Namibia' => 'NA',
        'Nauru' => 'NR', 'Nepal' => 'NP', 'Netherlands' => 'NL', 'New Zealand' => 'NZ', 'Nicaragua' => 'NI',
        'Niger' => 'NE', 'Nigeria' => 'NG', 'North Korea' => 'KP', 'North Macedonia' => 'MK', 'Norway' => 'NO',
        'Oman' => 'OM', 'Pakistan' => 'PK', 'Palau' => 'PW', 'Palestine' => 'PS', 'Panama' => 'PA',
        'Papua New Guinea' => 'PG', 'Paraguay' => 'PY', 'Peru' => 'PE', 'Philippines' => 'PH', 'Poland' => 'PL',
        'Portugal' => 'PT', 'Qatar' => 'QA', 'Republic of the Congo' => 'CG', 'Romania' => 'RO', 'Rwanda' => 'RW',
        'Russia' => 'RU', 'Saint Kitts and Nevis' => 'KN', 'Saint Lucia' => 'LC', 'Saint Vincent and the Grenadines' => 'VC', 'Samoa' => 'WS',
        'San Marino' => 'SM', 'São Tomé and Príncipe' => 'ST', 'Saudi Arabia' => 'SA', 'Senegal' => 'SN', 'Serbia' => 'RS',
        'Seychelles' => 'SC', 'Sierra Leone' => 'SL', 'Singapore' => 'SG', 'Slovakia' => 'SK', 'Slovenia' => 'SI',
        'Somalia' => 'SO', 'South Africa' => 'ZA', 'South Korea' => 'KR', 'South Sudan' => 'SS', 'Spain' => 'ES',
        'Sri Lanka' => 'LK', 'Sudan' => 'SD', 'Suriname' => 'SR', 'Sweden' => 'SE', 'Switzerland' => 'CH',
        'Syria' => 'SY', 'Tajikistan' => 'TJ', 'Tanzania' => 'TZ', 'Thailand' => 'TH', 'Togo' => 'TG',
        'Tonga' => 'TO', 'Trinidad and Tobago' => 'TT', 'Tunisia' => 'TN', 'Turkey' => 'TR', 'Turkmenistan' => 'TM',
        'Tuvalu' => 'TV', 'Uganda' => 'UG', 'Ukraine' => 'UA', 'United Arab Emirates' => 'AE', 'United Kingdom' => 'GB',
        'United States of America' => 'US', 'Uruguay' => 'UY', 'Uzbekistan' => 'UZ', 'Vanuatu' => 'VU', 'Vatican City' => 'VA',
        'Venezuela' => 'VE', 'Vietnam' => 'VN', 'Yemen' => 'YE', 'Zambia' => 'ZM', 'Zimbabwe' => 'ZW',
    ];

    public static function forName(string $name): ?string
    {
        return self::BY_NAME[$name] ?? null;
    }
}
```

- [ ] **Step 4: Migration**

Run: `php artisan make:migration add_iso_code_to_countries_table --table=countries --no-interaction`

```php
public function up(): void
{
    Schema::table('countries', function (Blueprint $table) {
        $table->char('iso_code', 2)->nullable()->unique()->after('name');
    });

    foreach (DB::table('countries')->get(['id', 'name']) as $country) {
        $isoCode = CountryIsoCodes::forName($country->name);

        if ($isoCode !== null) {
            DB::table('countries')->where('id', $country->id)->update(['iso_code' => $isoCode]);
        }
    }
}

public function down(): void
{
    Schema::table('countries', function (Blueprint $table) {
        $table->dropUnique(['iso_code']);
        $table->dropColumn('iso_code');
    });
}
```

(imports: `App\Support\CountryIsoCodes`, `Illuminate\Support\Facades\DB`)

- [ ] **Step 5: Model, factory, seeder, requests**

`Country`: add `@property string|null $iso_code`, `#[Fillable(['name', 'iso_code'])]`, and normalise to upper case:

```php
/**
 * @return Attribute<string|null, string|null>
 */
protected function isoCode(): Attribute
{
    return Attribute::make(
        set: fn (?string $value): ?string => $value === null || $value === '' ? null : strtoupper($value),
    );
}
```

`CountryFactory`: `'iso_code' => null` in `definition()`, plus states:

```php
public function italy(): static
{
    return $this->state(fn (): array => ['name' => 'Italy', 'iso_code' => 'IT']);
}

public function spain(): static
{
    return $this->state(fn (): array => ['name' => 'Spain', 'iso_code' => 'ES']);
}
```

`CountrySeeder`: replace the `$names` list with `CountryIsoCodes::BY_NAME`:

```php
DB::table('countries')->insert(array_map(
    static fn (string $name, string $isoCode): array => [
        'id' => (string) Str::uuid(),
        'name' => $name,
        'iso_code' => $isoCode,
        'created_at' => $now,
        'updated_at' => $now,
    ],
    array_keys(CountryIsoCodes::BY_NAME),
    CountryIsoCodes::BY_NAME,
));
```

`StoreCountryRequest` rules: add `'iso_code' => ['nullable', 'string', 'size:2', 'alpha', Rule::unique('countries', 'iso_code')]`. `UpdateCountryRequest`: same with `->ignore($this->route('country'))`. Add `prepareForValidation()` uppercasing it (`$this->merge(['iso_code' => $this->filled('iso_code') ? strtoupper($this->string('iso_code')->toString()) : null])`) so uniqueness is checked case-insensitively.

- [ ] **Step 6: Vue forms**

`countries/Create.vue` and `countries/Edit.vue`: add a field after the name, same markup as name (`Label`, `Input` with `maxlength="2"`, `InputError`), bound to `iso_code` (Edit default `country.iso_code ?? ''`). `countries/Index.vue`: add an "ISO" column showing `country.iso_code ?? '—'`. Add `countries.create.isoCode: 'ISO code'` (it: `'Codice ISO'`, es: `'Código ISO'`) and `countries.index.columns.isoCode: 'ISO'` to the three lang files.

- [ ] **Step 7: Run tests**

Run: `php artisan test --compact tests/Feature/CountryTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add iso_code to countries"
```

---

### Task 2: Fiscal columns on Company and Customer

**Files:**
- Create: migration `add_fiscal_columns_to_companies_and_customers`
- Modify: `app/Models/Company.php`, `app/Models/Customer.php`, factories
- Modify: `app/Http/Requests/{Store,Update}CompanyRequest.php`, `{Store,Update}CustomerRequest.php`
- Modify: `app/Http/Controllers/CompanyController.php`, `CustomerController.php` (countries with `iso_code`)
- Modify: `app/Mcp/Tools/CreateCustomerTool.php`, `app/Mcp/Tools/ListCompaniesTool.php`
- Modify: `resources/views/invoices/template.blade.php`, `resources/views/estimations/template.blade.php`
- Modify: `resources/js/pages/companies/{Create,Edit}.vue`, `resources/js/pages/customers/{Create,Edit}.vue`, lang files
- Test: `tests/Feature/CompanyTest.php`, `tests/Feature/CustomerTest.php`, `tests/Feature/FiscalColumnsMigrationTest.php`, MCP tests

**Interfaces:**
- Produces: `companies.vat_number`, `companies.tax_code`, `companies.province`, `companies.fiscal_details` (json); `customers.vat_number`, `customers.tax_code`, `customers.fiscal_details`. Models cast `fiscal_details` to `array`. Requests accept `fiscal_details` as `nullable|array` (country-specific keys added in Task 5).

- [ ] **Step 1: Write the failing migration test** `tests/Feature/FiscalColumnsMigrationTest.php`

```php
<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('renaming tax_id and nif to vat_number keeps the data', function () {
    $migration = include database_path('migrations/'.collect(scandir(database_path('migrations')))
        ->first(fn (string $file): bool => str_ends_with($file, '_add_fiscal_columns_to_companies_and_customers.php')));

    $migration->down();

    $countryId = (string) Str::uuid();
    DB::table('countries')->insert(['id' => $countryId, 'name' => 'X', 'created_at' => now(), 'updated_at' => now()]);
    $companyId = (string) Str::uuid();
    DB::table('companies')->insert(['id' => $companyId, 'name' => 'ACME', 'tax_id' => 'IT01234567890', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('customers')->insert(['id' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => 'Bob', 'nif' => 'B12345678', 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();

    expect(DB::table('companies')->value('vat_number'))->toBe('IT01234567890');
    expect(DB::table('customers')->value('vat_number'))->toBe('B12345678');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/FiscalColumnsMigrationTest.php`
Expected: FAIL (migration file not found).

- [ ] **Step 3: Migration**

Run: `php artisan make:migration add_fiscal_columns_to_companies_and_customers --no-interaction`

```php
public function up(): void
{
    Schema::table('companies', function (Blueprint $table) {
        $table->renameColumn('tax_id', 'vat_number');
    });

    Schema::table('companies', function (Blueprint $table) {
        $table->string('tax_code', 50)->nullable()->after('vat_number');
        $table->string('province', 10)->nullable()->after('city');
        $table->json('fiscal_details')->nullable();
    });

    Schema::table('customers', function (Blueprint $table) {
        $table->renameColumn('nif', 'vat_number');
    });

    Schema::table('customers', function (Blueprint $table) {
        $table->string('tax_code', 50)->nullable()->after('vat_number');
        $table->json('fiscal_details')->nullable();
    });
}

public function down(): void
{
    Schema::table('companies', function (Blueprint $table) {
        $table->dropColumn(['tax_code', 'province', 'fiscal_details']);
    });

    Schema::table('companies', function (Blueprint $table) {
        $table->renameColumn('vat_number', 'tax_id');
    });

    Schema::table('customers', function (Blueprint $table) {
        $table->dropColumn(['tax_code', 'fiscal_details']);
    });

    Schema::table('customers', function (Blueprint $table) {
        $table->renameColumn('vat_number', 'nif');
    });
}
```

- [ ] **Step 4: Models and factories**

`Company`: replace `tax_id` with `vat_number` in docblock and `#[Fillable]`; add `tax_code`, `province`, `fiscal_details` (`@property array<string, mixed>|null $fiscal_details`); cast `'fiscal_details' => 'array'`.
`Customer`: replace `nif` with `vat_number`; add `tax_code`, `fiscal_details`; add `casts()` returning `['fiscal_details' => 'array']`.
`CompanyFactory`: `'vat_number' => fake()->numerify('###########')`, `'tax_code' => null`, `'province' => null`, `'fiscal_details' => null`.
`CustomerFactory`: `'vat_number' => fake()->numerify('########')`, `'tax_code' => null`, `'fiscal_details' => null`.

- [ ] **Step 5: Requests and controllers**

Company requests: rename `tax_id` → `vat_number` in rules and in the `prepareForValidation()` list; add

```php
'tax_code' => ['nullable', 'string', 'max:50'],
'province' => ['nullable', 'string', 'max:10'],
'fiscal_details' => ['nullable', 'array'],
```

and add `tax_code`, `province` to the normalised list. Customer requests: rename `nif` → `vat_number`; add `tax_code` and `fiscal_details` rules as above.

In `CompanyController::create/edit` and in `CustomerController` wherever countries are loaded, select `['id', 'name', 'iso_code']`.

- [ ] **Step 6: MCP tools and PDF templates**

`CreateCustomerTool::schema()`: replace `'nif'` with `'vat_number' => $schema->string()->description('VAT number (partita IVA / NIF).')` and add `'tax_code' => $schema->string()->description('Tax code (codice fiscale) when different from the VAT number.')`. `ListCompaniesTool`: `tax_id` → `vat_number` in the `orWhere` and the `get()` columns. Update `tests/Feature/Mcp/ListCompaniesToolTest.php` and `CreateCustomerToolTest.php` if they reference `tax_id`/`nif`.

Both Blade templates: `$…->company->tax_id` → `$…->company->vat_number`, `$…->customer->nif` → `$…->customer->vat_number`. After the customer VAT line add:

```blade
@if($invoice->customer->tax_code)
    {{ __('invoice.tax_code') }}: {{ $invoice->customer->tax_code }}<br>
@endif
```

(same in the estimation template with `$estimation` and `estimation.tax_code`). Add `'tax_code' => 'Tax code'` / `'Codice fiscale'` / `'NIF'` to `resources/lang/{en,it,es}/{invoice,estimation}.php`.

- [ ] **Step 7: Update existing tests**

In `tests/Feature/CompanyTest.php` replace `tax_id` with `vat_number` (lines ~362–434). Add to `CompanyTest.php`:

```php
test('company fiscal fields are stored', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'ACME',
        'vat_number' => 'IT01234567890',
        'tax_code' => 'RSSMRA80A01H501U',
        'province' => 'RM',
        'fiscal_details' => ['tax_regime' => 'RF01'],
    ])->assertRedirect(route('companies.index'));

    $company = Company::query()->where('name', 'ACME')->firstOrFail();
    expect($company->vat_number)->toBe('IT01234567890');
    expect($company->tax_code)->toBe('RSSMRA80A01H501U');
    expect($company->province)->toBe('RM');
    expect($company->fiscal_details)->toBe(['tax_regime' => 'RF01']);
});
```

Add the equivalent customer test in `tests/Feature/CustomerTest.php` (`vat_number`, `tax_code`, `fiscal_details => ['pec' => 'a@pec.it']`), following that file's route/acting pattern.

- [ ] **Step 8: Vue forms**

`companies/Create.vue` + `Edit.vue`: rename `tax_id` → `vat_number` in the type, form and markup; label key `companies.create.vatNumber`. Add `tax_code` and `province` inputs (province in the zip/city row, grid becomes `grid-cols-3`). Add `fiscal_details: props.company.fiscal_details ?? {}` (Create: `{}`) to the form (rendered in Task 5). Country type gains `iso_code: string | null`.
`customers/Create.vue` + `Edit.vue`: `name="nif"` → `name="vat_number"`, `errors.nif` → `errors.vat_number`, label `customers.create.vatNumber`; add a `tax_code` input (`name="tax_code"`).
Lang (`en`/`it`/`es`): rename `taxId`/`taxIdPlaceholder` → `vatNumber`/`vatNumberPlaceholder` under `companies.create` and `customers.create` (values: en `VAT number`, it `Partita IVA`, es `NIF-IVA`); add `taxCode` (`Tax code` / `Codice fiscale` / `NIF`), `taxCodePlaceholder`, and `companies.create.province` (`Province` / `Provincia` / `Provincia`).

- [ ] **Step 9: Run tests and type-check**

Run: `php artisan test --compact tests/Feature/FiscalColumnsMigrationTest.php tests/Feature/CompanyTest.php tests/Feature/CustomerTest.php tests/Feature/Mcp tests/Feature/InvoiceTemplateTest.php tests/Feature/EstimationTemplateTest.php`
Expected: PASS.
Run: `npm run types:check` and `npm run lint:check`.
Expected: no errors.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: rename tax_id/nif to vat_number and add fiscal columns"
```

---

### Task 3: `vat_exemption_code` on invoice and estimation rows

**Files:**
- Create: migration `add_vat_exemption_code_to_row_tables`
- Modify: `app/Models/InvoiceRow.php`, `app/Models/EstimationRow.php`
- Modify: `app/Http/Requests/{Store,Update}InvoiceRequest.php`, `{Store,Update}EstimationRequest.php`
- Modify: `app/Http/Controllers/EstimationController.php` (`convertToInvoice`), `InvoiceController::create` (duplicate rows)
- Modify: `app/Mcp/Tools/CreateInvoiceTool.php`, `CreateEstimationTool.php` (schema)
- Test: `tests/Feature/InvoiceRowTest.php`, `tests/Feature/EstimationConversionTest.php`

**Interfaces:**
- Produces: `invoice_rows.vat_exemption_code`, `estimation_rows.vat_exemption_code` (`string(10)`, nullable). Request rule `'rows.*.vat_exemption_code' => ['nullable', 'string', 'max:10']` (narrowed per country in Task 5).

- [ ] **Step 1: Failing tests**

In `tests/Feature/EstimationConversionTest.php` (follow its existing setup for an accepted estimation):

```php
test('conversion copies the vat exemption code', function () {
    // build an accepted estimation as the other tests in this file do, with one row:
    // EstimationRow::factory()->for($estimation)->create(['vat_rate' => 0, 'vat_exemption_code' => 'N2.1'])
    // POST the conversion route as the existing tests do, then:
    $invoice = Invoice::query()->latest()->firstOrFail();
    expect($invoice->rows->first()->vat_exemption_code)->toBe('N2.1');
});
```

In `tests/Feature/InvoiceRowTest.php`:

```php
test('invoice rows store the vat exemption code', function () {
    $row = InvoiceRow::factory()->create(['vat_rate' => 0, 'vat_exemption_code' => 'N3.1']);

    expect($row->fresh()->vat_exemption_code)->toBe('N3.1');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/InvoiceRowTest.php tests/Feature/EstimationConversionTest.php`
Expected: FAIL (unknown column).

- [ ] **Step 3: Implement**

Migration (`php artisan make:migration add_vat_exemption_code_to_row_tables --no-interaction`):

```php
public function up(): void
{
    foreach (['invoice_rows', 'estimation_rows'] as $tableName) {
        Schema::table($tableName, function (Blueprint $table) {
            $table->string('vat_exemption_code', 10)->nullable()->after('vat_rate');
        });
    }
}

public function down(): void
{
    foreach (['invoice_rows', 'estimation_rows'] as $tableName) {
        Schema::table($tableName, function (Blueprint $table) {
            $table->dropColumn('vat_exemption_code');
        });
    }
}
```

Add `vat_exemption_code` to `#[Fillable]` and docblock (`@property string|null $vat_exemption_code`) of both row models. Add the request rule to the four requests. In `convertToInvoice` add `'vat_exemption_code' => $row->vat_exemption_code` to the created row; in `InvoiceController::create` duplicate rows add `'vat_exemption_code' => $row->vat_exemption_code`. In both MCP create tools add `'vat_exemption_code' => $schema->string()->description('VAT exemption code, required when vat_rate is 0 (IT: N1–N7, ES: E1–E6, N1, N2).')` to the row object.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/InvoiceRowTest.php tests/Feature/EstimationConversionTest.php tests/Feature/InvoiceTest.php tests/Feature/EstimationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add vat_exemption_code to invoice and estimation rows"
```

---

### Task 4: Country compliance rules

**Files:**
- Create: `app/EInvoicing/Enums/SubmissionStatus.php`, `app/Enums/InvoiceStatus.php`
- Create: `app/EInvoicing/Contracts/CountryComplianceRules.php`
- Create: `app/EInvoicing/Compliance/{DefaultComplianceRules,ItalyComplianceRules,SpainComplianceRules}.php`
- Create: `app/EInvoicing/CountryComplianceResolver.php`
- Test: `tests/Feature/EInvoicing/CountryComplianceRulesTest.php`

**Interfaces:**
- Consumes: `Country::$iso_code`, `Company/Customer::$vat_number|$tax_code|$province|$state|$fiscal_details`, `InvoiceRow::$vat_exemption_code`.
- Produces:
  - `enum SubmissionStatus: string { Pending='pending'; Failed='failed'; Submitted='submitted'; Rejected='rejected'; Accepted='accepted'; Delivered='delivered'; NotDelivered='not_delivered' }` with `isFinal(): bool` (everything except `Pending`, `Submitted`) and `isAwaitingAuthority(): bool` (`Pending`, `Submitted`).
  - `enum InvoiceStatus: string { Draft='draft'; Issued='issued' }` (used from Task 6).
  - `CountryComplianceRules` (methods in the spec § 3), `validateForIssue(Invoice): array<string, string>` (key = field path, value = translated message).
  - `CountryComplianceResolver::forCompany(Company $company): CountryComplianceRules`, `::forIsoCode(?string $isoCode): CountryComplianceRules`, `::forCountryId(?string $countryId): CountryComplianceRules`.

- [ ] **Step 1: Write the failing tests** `tests/Feature/EInvoicing/CountryComplianceRulesTest.php`

```php
<?php

use App\EInvoicing\Compliance\DefaultComplianceRules;
use App\EInvoicing\Compliance\ItalyComplianceRules;
use App\EInvoicing\Compliance\SpainComplianceRules;
use App\EInvoicing\CountryComplianceResolver;
use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;

function italianCompany(): Company
{
    return Company::factory()->create([
        'country_id' => Country::factory()->italy(),
        'vat_number' => '01234567890',
        'address' => 'Via Roma 1', 'zip' => '00100', 'city' => 'Roma', 'province' => 'RM',
        'fiscal_details' => ['tax_regime' => 'RF01'],
    ]);
}

function spanishCompany(): Company
{
    return Company::factory()->create([
        'country_id' => Country::factory()->spain(),
        'vat_number' => 'B12345678',
    ]);
}

function invoiceFor(Company $company, array $customerAttributes, array $rowAttributes = []): Invoice
{
    $customer = Customer::factory()->create(['company_id' => $company->id, ...$customerAttributes]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id]);
    InvoiceRow::factory()->for($invoice)->create(['vat_rate' => 22, 'vat_exemption_code' => null, ...$rowAttributes]);

    return $invoice->load(['company.country', 'customer.country', 'rows']);
}

test('resolver picks rules by iso code and falls back to default', function () {
    expect(CountryComplianceResolver::forIsoCode('IT'))->toBeInstanceOf(ItalyComplianceRules::class);
    expect(CountryComplianceResolver::forIsoCode('ES'))->toBeInstanceOf(SpainComplianceRules::class);
    expect(CountryComplianceResolver::forIsoCode('FR'))->toBeInstanceOf(DefaultComplianceRules::class);
    expect(CountryComplianceResolver::forIsoCode(null))->toBeInstanceOf(DefaultComplianceRules::class);
    expect(CountryComplianceResolver::forCompany(Company::factory()->create(['country_id' => null])))
        ->toBeInstanceOf(DefaultComplianceRules::class);
});

test('submission and revision policy per country', function () {
    expect((new DefaultComplianceRules)->requiresSubmission())->toBeFalse();
    expect((new ItalyComplianceRules)->requiresSubmission())->toBeTrue();
    expect((new SpainComplianceRules)->requiresSubmission())->toBeTrue();

    expect((new ItalyComplianceRules)->allowsRevisionAfter(SubmissionStatus::Failed))->toBeTrue();
    expect((new ItalyComplianceRules)->allowsRevisionAfter(SubmissionStatus::Rejected))->toBeTrue();
    expect((new ItalyComplianceRules)->allowsRevisionAfter(SubmissionStatus::Delivered))->toBeFalse();
    expect((new SpainComplianceRules)->allowsRevisionAfter(SubmissionStatus::Failed))->toBeTrue();
    expect((new SpainComplianceRules)->allowsRevisionAfter(SubmissionStatus::Rejected))->toBeFalse();
});

test('vat exemption codes per country', function () {
    expect((new ItalyComplianceRules)->vatExemptionCodes())->toContain('N2.1', 'N3.1', 'N7')->not->toContain('E1');
    expect((new SpainComplianceRules)->vatExemptionCodes())->toContain('E1', 'E6', 'N1', 'N2')->not->toContain('N2.1');
    expect((new DefaultComplianceRules)->vatExemptionCodes())->toBe([]);
});

test('italian invoices validate', function (array $customer, array $row, array $expectedErrorKeys) {
    $company = italianCompany();
    $customer['country_id'] = Country::query()->where('iso_code', $customer['country'])->value('id')
        ?? Country::factory()->create(['iso_code' => $customer['country']])->id;
    unset($customer['country']);

    $errors = (new ItalyComplianceRules)->validateForIssue(invoiceFor($company, $customer, $row));

    expect(array_keys($errors))->toEqualCanonicalizing($expectedErrorKeys);
})->with([
    'b2b ok with recipient code' => [['country' => 'IT', 'vat_number' => '09876543210', 'fiscal_details' => ['recipient_code' => 'ABC1234']], [], []],
    'b2b ok with pec' => [['country' => 'IT', 'vat_number' => '09876543210', 'fiscal_details' => ['pec' => 'x@pec.it']], [], []],
    'b2b missing recipient' => [['country' => 'IT', 'vat_number' => '09876543210', 'fiscal_details' => null], [], ['customer.fiscal_details.recipient_code']],
    'b2c needs tax code' => [['country' => 'IT', 'vat_number' => null, 'tax_code' => null], [], ['customer.tax_code']],
    'b2c ok' => [['country' => 'IT', 'vat_number' => null, 'tax_code' => 'RSSMRA80A01H501U'], [], []],
    'foreign needs vat number' => [['country' => 'DE', 'vat_number' => null], [], ['customer.vat_number']],
    'foreign ok' => [['country' => 'DE', 'vat_number' => 'DE123456789'], [], []],
    'zero rate needs natura' => [['country' => 'DE', 'vat_number' => 'DE123456789'], ['vat_rate' => 0], ['rows.0.vat_exemption_code']],
    'zero rate with natura' => [['country' => 'DE', 'vat_number' => 'DE123456789'], ['vat_rate' => 0, 'vat_exemption_code' => 'N2.1'], []],
    'zero rate with spanish code' => [['country' => 'DE', 'vat_number' => 'DE123456789'], ['vat_rate' => 0, 'vat_exemption_code' => 'E1'], ['rows.0.vat_exemption_code']],
]);

test('italian company data is required', function () {
    $company = italianCompany();
    $company->update(['vat_number' => null, 'province' => null, 'fiscal_details' => null]);

    $invoice = invoiceFor($company, ['country_id' => $company->country_id, 'vat_number' => '09876543210', 'fiscal_details' => ['recipient_code' => 'ABC1234']]);

    expect(array_keys((new ItalyComplianceRules)->validateForIssue($invoice)))
        ->toEqualCanonicalizing(['company.vat_number', 'company.province', 'company.fiscal_details.tax_regime']);
});

test('spanish invoices validate', function (array $customer, array $row, array $expectedErrorKeys) {
    $company = spanishCompany();
    $customer['country_id'] = Country::query()->where('iso_code', $customer['country'])->value('id')
        ?? Country::factory()->create(['iso_code' => $customer['country']])->id;
    unset($customer['country']);

    $errors = (new SpainComplianceRules)->validateForIssue(invoiceFor($company, $customer, $row));

    expect(array_keys($errors))->toEqualCanonicalizing($expectedErrorKeys);
})->with([
    'domestic ok' => [['country' => 'ES', 'vat_number' => 'B87654321'], [], []],
    'domestic missing nif' => [['country' => 'ES', 'vat_number' => null], [], ['customer.vat_number']],
    'foreign needs id type' => [['country' => 'FR', 'vat_number' => 'FR12345678901', 'fiscal_details' => null], [], ['customer.fiscal_details.id_type']],
    'foreign ok' => [['country' => 'FR', 'vat_number' => 'FR12345678901', 'fiscal_details' => ['id_type' => '02']], [], []],
    'zero rate needs cause' => [['country' => 'ES', 'vat_number' => 'B87654321'], ['vat_rate' => 0], ['rows.0.vat_exemption_code']],
    'zero rate with cause' => [['country' => 'ES', 'vat_number' => 'B87654321'], ['vat_rate' => 0, 'vat_exemption_code' => 'E5'], []],
]);

test('spanish company needs a nif', function () {
    $company = spanishCompany();
    $company->update(['vat_number' => null]);

    $invoice = invoiceFor($company, ['country_id' => $company->country_id, 'vat_number' => 'B87654321']);

    expect(array_keys((new SpainComplianceRules)->validateForIssue($invoice)))->toBe(['company.vat_number']);
});

test('default rules only require at least one row', function () {
    $invoice = Invoice::factory()->create();

    expect(array_keys((new DefaultComplianceRules)->validateForIssue($invoice->load('rows'))))->toBe(['rows']);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/EInvoicing/CountryComplianceRulesTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Enums**

`app/EInvoicing/Enums/SubmissionStatus.php`:

```php
<?php

namespace App\EInvoicing\Enums;

enum SubmissionStatus: string
{
    case Pending = 'pending';
    case Failed = 'failed';
    case Submitted = 'submitted';
    case Rejected = 'rejected';
    case Accepted = 'accepted';
    case Delivered = 'delivered';
    case NotDelivered = 'not_delivered';

    public function isAwaitingAuthority(): bool
    {
        return in_array($this, [self::Pending, self::Submitted], true);
    }

    public function isFinal(): bool
    {
        return ! $this->isAwaitingAuthority();
    }
}
```

`app/Enums/InvoiceStatus.php`:

```php
<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
}
```

- [ ] **Step 4: Contract**

`app/EInvoicing/Contracts/CountryComplianceRules.php`:

```php
<?php

namespace App\EInvoicing\Contracts;

use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Invoice;

interface CountryComplianceRules
{
    /**
     * Validation rules for the keys of companies.fiscal_details, without the "fiscal_details." prefix.
     *
     * @return array<string, array<mixed>>
     */
    public function companyFiscalRules(): array;

    /**
     * Validation rules for the keys of customers.fiscal_details, without the "fiscal_details." prefix.
     *
     * @return array<string, array<mixed>>
     */
    public function customerFiscalRules(): array;

    /**
     * @return list<string>
     */
    public function vatExemptionCodes(): array;

    /**
     * Check that the invoice (with company.country, customer.country and rows loaded) can be issued.
     *
     * @return array<string, string> field path => translated message
     */
    public function validateForIssue(Invoice $invoice): array;

    public function requiresSubmission(): bool;

    public function allowsRevisionAfter(SubmissionStatus $status): bool;
}
```

- [ ] **Step 5: Default rules** `app/EInvoicing/Compliance/DefaultComplianceRules.php`

```php
<?php

namespace App\EInvoicing\Compliance;

use App\EInvoicing\Contracts\CountryComplianceRules;
use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Invoice;

class DefaultComplianceRules implements CountryComplianceRules
{
    public function companyFiscalRules(): array
    {
        return [];
    }

    public function customerFiscalRules(): array
    {
        return [];
    }

    public function vatExemptionCodes(): array
    {
        return [];
    }

    public function validateForIssue(Invoice $invoice): array
    {
        if ($invoice->rows->isEmpty()) {
            return ['rows' => __('The invoice must have at least one row.')];
        }

        return [];
    }

    public function requiresSubmission(): bool
    {
        return false;
    }

    public function allowsRevisionAfter(SubmissionStatus $status): bool
    {
        return false;
    }
}
```

- [ ] **Step 6: Italy rules** `app/EInvoicing/Compliance/ItalyComplianceRules.php`

```php
<?php

namespace App\EInvoicing\Compliance;

use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use Illuminate\Validation\Rule;

class ItalyComplianceRules extends DefaultComplianceRules
{
    /**
     * @var list<string>
     */
    public const TAX_REGIMES = [
        'RF01', 'RF02', 'RF04', 'RF05', 'RF06', 'RF07', 'RF08', 'RF09', 'RF10',
        'RF11', 'RF12', 'RF13', 'RF14', 'RF15', 'RF16', 'RF17', 'RF18', 'RF19',
    ];

    public function companyFiscalRules(): array
    {
        return [
            'tax_regime' => ['nullable', 'string', Rule::in(self::TAX_REGIMES)],
            'rea_office' => ['nullable', 'string', 'size:2', 'alpha'],
            'rea_number' => ['nullable', 'string', 'max:20'],
            'share_capital' => ['nullable', 'numeric', 'min:0'],
            'liquidation_status' => ['nullable', 'string', Rule::in(['LS', 'LN'])],
        ];
    }

    public function customerFiscalRules(): array
    {
        return [
            'recipient_code' => ['nullable', 'string', 'size:7', 'alpha_num'],
            'pec' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function vatExemptionCodes(): array
    {
        return [
            'N1', 'N2.1', 'N2.2', 'N3.1', 'N3.2', 'N3.3', 'N3.4', 'N3.5', 'N3.6', 'N4', 'N5',
            'N6.1', 'N6.2', 'N6.3', 'N6.4', 'N6.5', 'N6.6', 'N6.7', 'N6.8', 'N6.9', 'N7',
        ];
    }

    public function validateForIssue(Invoice $invoice): array
    {
        $errors = parent::validateForIssue($invoice);
        $company = $invoice->company;
        $customer = $invoice->customer;

        if (blank($company->vat_number)) {
            $errors['company.vat_number'] = __('The company VAT number is required.');
        }

        if (blank($company->fiscal_details['tax_regime'] ?? null)) {
            $errors['company.fiscal_details.tax_regime'] = __('The company tax regime is required.');
        }

        foreach (['address', 'zip', 'city', 'province'] as $field) {
            if (blank($company->{$field})) {
                $errors["company.{$field}"] = __('The company address is incomplete.');
            }
        }

        $isDomesticCustomer = $customer->country?->iso_code === 'IT';

        if (! $isDomesticCustomer) {
            if (blank($customer->vat_number)) {
                $errors['customer.vat_number'] = __('A foreign customer needs a VAT number.');
            }
        } elseif (filled($customer->vat_number)) {
            if (blank($customer->fiscal_details['recipient_code'] ?? null) && blank($customer->fiscal_details['pec'] ?? null)) {
                $errors['customer.fiscal_details.recipient_code'] = __('A business customer needs a recipient code or a PEC address.');
            }
        } elseif (blank($customer->tax_code)) {
            $errors['customer.tax_code'] = __('A private customer needs a tax code.');
        }

        return [...$errors, ...$this->exemptionCodeErrors($invoice)];
    }

    public function requiresSubmission(): bool
    {
        return true;
    }

    public function allowsRevisionAfter(SubmissionStatus $status): bool
    {
        return in_array($status, [SubmissionStatus::Failed, SubmissionStatus::Rejected], true);
    }

    /**
     * @return array<string, string>
     */
    protected function exemptionCodeErrors(Invoice $invoice): array
    {
        $errors = [];

        $invoice->rows->values()->each(function (InvoiceRow $row, int $index) use (&$errors): void {
            if ((float) $row->vat_rate === 0.0 && ! in_array($row->vat_exemption_code, $this->vatExemptionCodes(), true)) {
                $errors["rows.{$index}.vat_exemption_code"] = __('Rows with a 0% VAT rate need a valid exemption code.');
            }
        });

        return $errors;
    }
}
```

Note: the four address fields share one message but each gets its own key; the "italian company data is required" test only blanks `vat_number`, `province` and `fiscal_details`, so only `company.province` appears from the address loop.

- [ ] **Step 7: Spain rules** `app/EInvoicing/Compliance/SpainComplianceRules.php`

```php
<?php

namespace App\EInvoicing\Compliance;

use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Invoice;
use Illuminate\Validation\Rule;

class SpainComplianceRules extends ItalyComplianceRules
{
    /**
     * @var list<string>
     */
    public const ID_TYPES = ['02', '03', '04', '05', '06', '07'];

    public function companyFiscalRules(): array
    {
        return [
            'special_regime' => ['nullable', 'string', 'size:2'],
        ];
    }

    public function customerFiscalRules(): array
    {
        return [
            'id_type' => ['nullable', 'string', Rule::in(self::ID_TYPES)],
        ];
    }

    public function vatExemptionCodes(): array
    {
        return ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'N1', 'N2'];
    }

    public function validateForIssue(Invoice $invoice): array
    {
        $errors = $invoice->rows->isEmpty()
            ? ['rows' => __('The invoice must have at least one row.')]
            : [];

        if (blank($invoice->company->vat_number)) {
            $errors['company.vat_number'] = __('The company NIF is required.');
        }

        $customer = $invoice->customer;

        if (blank($customer->vat_number)) {
            $errors['customer.vat_number'] = __('The customer tax identifier is required.');
        }

        if ($customer->country?->iso_code !== 'ES' && blank($customer->fiscal_details['id_type'] ?? null)) {
            $errors['customer.fiscal_details.id_type'] = __('A foreign customer needs an identifier type.');
        }

        return [...$errors, ...$this->exemptionCodeErrors($invoice)];
    }

    public function allowsRevisionAfter(SubmissionStatus $status): bool
    {
        return $status === SubmissionStatus::Failed;
    }
}
```

(Spain extends Italy only to reuse `exemptionCodeErrors()` and `requiresSubmission()`; every other method is overridden.)

- [ ] **Step 8: Resolver** `app/EInvoicing/CountryComplianceResolver.php`

```php
<?php

namespace App\EInvoicing;

use App\EInvoicing\Compliance\DefaultComplianceRules;
use App\EInvoicing\Compliance\ItalyComplianceRules;
use App\EInvoicing\Compliance\SpainComplianceRules;
use App\EInvoicing\Contracts\CountryComplianceRules;
use App\Models\Company;
use App\Models\Country;

class CountryComplianceResolver
{
    public static function forCompany(Company $company): CountryComplianceRules
    {
        return self::forIsoCode($company->country?->iso_code);
    }

    public static function forCountryId(?string $countryId): CountryComplianceRules
    {
        return self::forIsoCode($countryId ? Country::query()->whereKey($countryId)->value('iso_code') : null);
    }

    public static function forIsoCode(?string $isoCode): CountryComplianceRules
    {
        return match ($isoCode) {
            'IT' => new ItalyComplianceRules,
            'ES' => new SpainComplianceRules,
            default => new DefaultComplianceRules,
        };
    }
}
```

- [ ] **Step 9: Translations**

Add every new `__()` string from Steps 5–7 to `resources/lang/it.json` and `resources/lang/es.json` (Italian and Spanish translations).

- [ ] **Step 10: Run tests**

Run: `php artisan test --compact tests/Feature/EInvoicing/CountryComplianceRulesTest.php`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add country compliance rules for Italy and Spain"
```

---

### Task 5: Country-aware validation and forms

**Files:**
- Modify: `app/Http/Requests/{Store,Update}CompanyRequest.php`, `{Store,Update}CustomerRequest.php`
- Modify: `app/Http/Requests/{Store,Update}InvoiceRequest.php`, `{Store,Update}EstimationRequest.php`
- Modify: `app/Http/Controllers/{InvoiceController,EstimationController,CustomerController}.php` (props)
- Create: `resources/js/lib/fiscalFields.ts`, `resources/js/components/FiscalDetailsFields.vue`, `resources/js/components/VatExemptionSelect.vue`
- Modify: `resources/js/pages/companies/{Create,Edit}.vue`, `customers/{Create,Edit}.vue`, `invoices/{Create,Edit}.vue`, `estimations/{Create,Edit}.vue`, lang files
- Test: `tests/Feature/CompanyTest.php`, `CustomerTest.php`, `InvoiceTest.php`

**Interfaces:**
- Consumes: `CountryComplianceResolver::forCountryId()`, `::forCompany()`, `CountryComplianceRules::companyFiscalRules()/customerFiscalRules()/vatExemptionCodes()`, `CurrentCompany::resolve()`.
- Produces: Inertia props `vatExemptionCodes: string[]` on invoice/estimation create/edit; `companyCountryIso: string|null` on customer create/edit; countries carry `iso_code`.

- [ ] **Step 1: Failing tests**

`CompanyTest.php`:

```php
test('italian company fiscal details are validated', function () {
    $italy = Country::factory()->italy()->create();

    $this->actingAs(User::factory()->create())->post(route('companies.store'), [
        'name' => 'ACME',
        'country_id' => $italy->id,
        'fiscal_details' => ['tax_regime' => 'RF99'],
    ])->assertSessionHasErrors('fiscal_details.tax_regime');
});

test('unknown fiscal detail keys are rejected', function () {
    $italy = Country::factory()->italy()->create();

    $this->actingAs(User::factory()->create())->post(route('companies.store'), [
        'name' => 'ACME',
        'country_id' => $italy->id,
        'fiscal_details' => ['tax_regime' => 'RF01', 'hack' => 'x'],
    ])->assertSessionHasErrors('fiscal_details');
});
```

`CustomerTest.php` (customers depend on the **current company's** country; create a default Italian company first — follow the file's pattern for current company):

```php
test('customer recipient code must be seven characters for italian companies', function () {
    Company::factory()->create(['is_default' => true, 'country_id' => Country::factory()->italy()]);

    $this->actingAs(User::factory()->create())->post(route('customers.store'), [
        'name' => 'Bob',
        'fiscal_details' => ['recipient_code' => 'ABC'],
    ])->assertSessionHasErrors('fiscal_details.recipient_code');
});
```

`InvoiceTest.php`:

```php
test('row vat exemption code must belong to the company country', function () {
    $company = Company::factory()->create(['is_default' => true, 'country_id' => Country::factory()->italy()]);
    $customer = Customer::factory()->create(['company_id' => $company->id]);

    $this->actingAs(User::factory()->create())->post(route('invoices.store'), [
        'invoice_date' => '2026-10-02',
        'customer_id' => $customer->id,
        'language' => 'it',
        'rows' => [['description' => 'X', 'quantity' => 1, 'price' => 10, 'vat_rate' => 0, 'vat_exemption_code' => 'E1']],
    ])->assertSessionHasErrors('rows.0.vat_exemption_code');
});

test('invoice create page exposes the company vat exemption codes', function () {
    Company::factory()->create(['is_default' => true, 'country_id' => Country::factory()->spain()]);

    $this->actingAs(User::factory()->create())->get(route('invoices.create'))
        ->assertInertia(fn ($page) => $page->where('vatExemptionCodes', ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'N1', 'N2']));
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/CompanyTest.php tests/Feature/CustomerTest.php tests/Feature/InvoiceTest.php`
Expected: the new tests FAIL.

- [ ] **Step 3: Backend validation**

Company requests — append to `rules()` (same in Store and Update):

```php
...$this->fiscalDetailsRules(),
```

and add the method:

```php
/**
 * @return array<string, array<mixed>>
 */
private function fiscalDetailsRules(): array
{
    $fiscalRules = CountryComplianceResolver::forCountryId($this->input('country_id'))->companyFiscalRules();

    return [
        // `array:` with an empty key list is invalid, so countries without rules accept only an empty object.
        'fiscal_details' => $fiscalRules === []
            ? ['nullable', 'array', 'max:0']
            : ['nullable', 'array:'.implode(',', array_keys($fiscalRules))],
        ...collect($fiscalRules)->mapWithKeys(fn (array $rules, string $key): array => ["fiscal_details.{$key}" => $rules])->all(),
    ];
}
```

This replaces the plain `'fiscal_details' => ['nullable', 'array']` rule added in Task 2. Update the Task 2 test "company fiscal fields are stored" to post `'country_id' => Country::factory()->italy()->create()->id` (with no country, `fiscal_details` must now be empty), and the equivalent customer test to create a default Italian company first. Also in `prepareForValidation()` drop empty strings inside `fiscal_details`:

```php
if (is_array($this->input('fiscal_details'))) {
    $normalized['fiscal_details'] = array_filter($this->input('fiscal_details'), fn ($value) => $value !== null && $value !== '') ?: null;
}
```

Customer requests: same method but with `CountryComplianceResolver::forCompany(CurrentCompany::resolve())` (guard null company: use `forIsoCode(null)`) and `customerFiscalRules()`.

Invoice and estimation requests — replace the row rule:

```php
'rows.*.vat_exemption_code' => ['nullable', 'string', 'max:10', ...$this->vatExemptionCodeRule()],
```

```php
/**
 * @return list<\Illuminate\Validation\Rules\In>
 */
private function vatExemptionCodeRule(): array
{
    $company = CurrentCompany::resolve();
    $codes = $company ? CountryComplianceResolver::forCompany($company)->vatExemptionCodes() : [];

    return $codes === [] ? [] : [Rule::in($codes)];
}
```

- [ ] **Step 4: Props**

`InvoiceController::create/edit`, `EstimationController::create/edit`: add

```php
'vatExemptionCodes' => CountryComplianceResolver::forCompany($company)->vatExemptionCodes(),
```

(`$company` = current company in `create`, `$invoice->company` / `$estimation->company` in `edit`.) `CustomerController::create/edit`: add `'companyCountryIso' => CurrentCompany::resolve()?->country?->iso_code`.

- [ ] **Step 5: Frontend helpers**

`resources/js/lib/fiscalFields.ts`:

```ts
export type FiscalField = {
    key: string;
    type: 'text' | 'email' | 'number' | 'select';
    options?: string[];
};

const italianTaxRegimes = [
    'RF01', 'RF02', 'RF04', 'RF05', 'RF06', 'RF07', 'RF08', 'RF09', 'RF10',
    'RF11', 'RF12', 'RF13', 'RF14', 'RF15', 'RF16', 'RF17', 'RF18', 'RF19',
];

const fields: Record<string, { company: FiscalField[]; customer: FiscalField[] }> = {
    IT: {
        company: [
            { key: 'tax_regime', type: 'select', options: italianTaxRegimes },
            { key: 'rea_office', type: 'text' },
            { key: 'rea_number', type: 'text' },
            { key: 'share_capital', type: 'number' },
            { key: 'liquidation_status', type: 'select', options: ['LS', 'LN'] },
        ],
        customer: [
            { key: 'recipient_code', type: 'text' },
            { key: 'pec', type: 'email' },
        ],
    },
    ES: {
        company: [{ key: 'special_regime', type: 'text' }],
        customer: [
            { key: 'id_type', type: 'select', options: ['02', '03', '04', '05', '06', '07'] },
        ],
    },
};

export function fiscalFieldsFor(
    isoCode: string | null | undefined,
    kind: 'company' | 'customer',
): FiscalField[] {
    return (isoCode && fields[isoCode]?.[kind]) || [];
}
```

`resources/js/components/FiscalDetailsFields.vue` (works with `useForm` via `v-model` and with `<Form>` via the `name` attributes):

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fiscalFieldsFor } from '@/lib/fiscalFields';

const props = defineProps<{
    isoCode: string | null | undefined;
    kind: 'company' | 'customer';
    errors?: Record<string, string | undefined>;
}>();

const model = defineModel<Record<string, string>>({ required: true });

const { t } = useI18n();

const fields = computed(() => fiscalFieldsFor(props.isoCode, props.kind));

function update(key: string, value: string | number): void {
    model.value = { ...model.value, [key]: String(value) };
}
</script>

<template>
    <fieldset v-if="fields.length" class="space-y-4 rounded-md border p-4">
        <legend class="px-1 text-sm font-medium">
            {{ t('fiscalDetails.title') }}
        </legend>
        <div v-for="field in fields" :key="field.key" class="grid gap-2">
            <Label :for="`fiscal_details_${field.key}`">
                {{ t(`fiscalDetails.fields.${field.key}`) }}
            </Label>
            <select
                v-if="field.type === 'select'"
                :id="`fiscal_details_${field.key}`"
                :name="`fiscal_details[${field.key}]`"
                :value="model[field.key] ?? ''"
                class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-input/30"
                @change="update(field.key, ($event.target as HTMLSelectElement).value)"
            >
                <option value="">—</option>
                <option v-for="option in field.options" :key="option" :value="option">
                    {{ option }} — {{ t(`fiscalDetails.options.${field.key}.${option}`) }}
                </option>
            </select>
            <Input
                v-else
                :id="`fiscal_details_${field.key}`"
                :name="`fiscal_details[${field.key}]`"
                :type="field.type"
                :model-value="model[field.key] ?? ''"
                @update:model-value="(value) => update(field.key, value)"
            />
            <InputError :message="errors?.[`fiscal_details.${field.key}`]" />
        </div>
    </fieldset>
</template>
```

`resources/js/components/VatExemptionSelect.vue`:

```vue
<script setup lang="ts">
import { useI18n } from 'vue-i18n';

defineProps<{ codes: string[] }>();

const model = defineModel<string | null>({ required: true });

const { t } = useI18n();
</script>

<template>
    <select
        :value="model ?? ''"
        :aria-label="t('vatExemption.label')"
        class="h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs dark:bg-input/30"
        @change="model = ($event.target as HTMLSelectElement).value || null"
    >
        <option value="">{{ t('vatExemption.placeholder') }}</option>
        <option v-for="code in codes" :key="code" :value="code">
            {{ code }}
        </option>
    </select>
</template>
```

- [ ] **Step 6: Wire into pages**

- `companies/Create.vue`, `Edit.vue`: compute `const countryIso = computed(() => props.countries.find((c) => c.id === form.country_id)?.iso_code ?? null);` and render `<FiscalDetailsFields v-model="form.fiscal_details" :iso-code="countryIso" kind="company" :errors="form.errors" />` after the country select. The form field type is `fiscal_details: {} as Record<string, string>`. Watch `form.country_id` and reset `form.fiscal_details = {}` when it changes to a country with a different ISO code.
- `customers/Create.vue`, `Edit.vue`: add prop `companyCountryIso: string | null`; `const fiscalDetails = ref<Record<string, string>>({ ...(props.customer?.fiscal_details ?? {}) })`; render `<FiscalDetailsFields v-model="fiscalDetails" :iso-code="companyCountryIso" kind="customer" :errors="errors" />` inside the `<Form>` slot (the `name` attributes submit the values).
- `invoices/Create.vue`, `Edit.vue`, `estimations/Create.vue`, `Edit.vue`: add prop `vatExemptionCodes: string[]`; add `vat_exemption_code: string | null` to the row types and to every place rows are built (`addRow`, initial form rows, duplicate). Under the VAT input, inside the same grid cell:

```vue
<VatExemptionSelect
    v-if="row.vat_rate === 0 && vatExemptionCodes.length"
    v-model="row.vat_exemption_code"
    :codes="vatExemptionCodes"
/>
<InputError :message="form.errors[`rows.${i}.vat_exemption_code`]" />
```

  Before submit, set `row.vat_exemption_code = row.vat_rate === 0 ? row.vat_exemption_code : null`.
- Lang files: add `fiscalDetails.title` (`Fiscal details` / `Dati fiscali` / `Datos fiscales`), `fiscalDetails.fields.{tax_regime,rea_office,rea_number,share_capital,liquidation_status,recipient_code,pec,special_regime,id_type}`, `fiscalDetails.options.tax_regime.RF01…RF19` (official short descriptions, e.g. RF01 `Ordinario`, RF19 `Forfettario`), `fiscalDetails.options.liquidation_status.{LS,LN}`, `fiscalDetails.options.id_type.{02…07}` (02 `NIF-IVA`, 03 `Passport`, 04 `Official ID of the country of residence`, 05 `Residence certificate`, 06 `Other document`, 07 `Not registered`), `vatExemption.label` (`VAT exemption` / `Natura esenzione` / `Causa de exención`), `vatExemption.placeholder` (`Select…`). The select shows the bare codes (N1 and N2 mean different things in Italy and Spain, so no per-code descriptions in this iteration).

- [ ] **Step 7: Run tests and checks**

Run: `php artisan test --compact tests/Feature/CompanyTest.php tests/Feature/CustomerTest.php tests/Feature/InvoiceTest.php tests/Feature/EstimationTest.php`
Expected: PASS.
Run: `npm run types:check` and `npm run lint:check`.
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: country-aware fiscal details and vat exemption codes in forms"
```

---

## Phase 2 — Draft / Issued lifecycle

### Task 6: `InvoiceStatus`, nullable number, `issued_at`

**Files:**
- Create: migration `add_status_to_invoices_table`
- Modify: `app/Models/Invoice.php`, `database/factories/InvoiceFactory.php`
- Modify: `app/Http/Requests/{Store,Update}InvoiceRequest.php` (remove `number`)
- Modify: `app/Http/Controllers/InvoiceController.php` (`index`, `create`), `app/Support/InvoicePdf.php`, `resources/views/invoices/template.blade.php`
- Modify: `resources/js/pages/invoices/{Create,Edit,Index}.vue`, `resources/js/components/dashboard/*` if it prints the number, lang files
- Create: `resources/js/lib/invoiceStatus.ts`
- Test: `tests/Feature/InvoiceTest.php`, `tests/Feature/InvoiceStatusMigrationTest.php`, `tests/Feature/InvoiceTemplateTest.php`

**Interfaces:**
- Produces: `invoices.status` (string, default `draft`), `invoices.number` nullable, `invoices.issued_at` nullable datetime. `Invoice::$status` cast to `InvoiceStatus`, `Invoice::isLocked(): bool`, `Invoice::displayNumber(): string` (number or translated "Draft"), `InvoiceFactory::issued()` state (status Issued, `issued_at` now, number assigned via `Invoice::nextNumber()`), `InvoiceFactory::draft()`. `Invoice::nextNumber()` unchanged. The `creating` hook **no longer** assigns a number.

- [ ] **Step 1: Failing tests**

Replace the numbering tests at the top of `tests/Feature/InvoiceTest.php` that rely on the `creating` hook ("first invoice of the year is numbered 0001", "subsequent invoices…", "a new calendar year…", "each company has its own…") with `nextNumber()`-based equivalents, keeping their intent:

```php
test('a new invoice is a draft without number', function () {
    $invoice = Invoice::factory()->create();

    expect($invoice->status)->toBe(InvoiceStatus::Draft);
    expect($invoice->number)->toBeNull();
    expect($invoice->issued_at)->toBeNull();
    expect($invoice->isLocked())->toBeFalse();
});

test('next number starts at 0001 and increments per company and year', function () {
    Carbon::setTestNow('2026-01-15');
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    expect(Invoice::nextNumber($companyA->id))->toBe('2026-0001');
    Invoice::factory()->issued()->create(['company_id' => $companyA->id]);
    expect(Invoice::nextNumber($companyA->id))->toBe('2026-0002');
    expect(Invoice::nextNumber($companyB->id))->toBe('2026-0001');

    Carbon::setTestNow('2027-01-01');
    expect(Invoice::nextNumber($companyA->id))->toBe('2027-0001');

    Carbon::setTestNow();
});

test('drafts do not consume numbers', function () {
    Carbon::setTestNow('2026-01-15');
    $company = Company::factory()->create();

    Invoice::factory()->count(3)->create(['company_id' => $company->id]);

    expect(Invoice::nextNumber($company->id))->toBe('2026-0001');

    Carbon::setTestNow();
});

test('the number cannot be set from the create form', function () {
    $company = Company::factory()->create(['is_default' => true]);
    $customer = Customer::factory()->create(['company_id' => $company->id]);

    $this->actingAs(User::factory()->create())->post(route('invoices.store'), [
        'number' => '2026-9999',
        'invoice_date' => '2026-10-02',
        'customer_id' => $customer->id,
        'language' => 'en',
        'rows' => [['description' => 'X', 'quantity' => 1, 'price' => 10, 'vat_rate' => 22]],
    ]);

    expect(Invoice::query()->firstOrFail()->number)->toBeNull();
});

test('draft pdf filename does not depend on a number', function () {
    $invoice = Invoice::factory()->create();

    expect(InvoicePdf::filename($invoice))->toBe('draft-'.$invoice->id.'.pdf');
});
```

Keep "an explicitly provided number is respected", the uniqueness tests and the remaining tests (factory still accepts `number`).

`tests/Feature/InvoiceStatusMigrationTest.php` (same technique as `FiscalColumnsMigrationTest`): run the migration's `down()`, insert an invoice row with `number = '2026-0001'` and `created_at = '2026-03-01 10:00:00'` via `DB::table`, run `up()`, assert `status = 'issued'`, `issued_at = '2026-03-01 10:00:00'`, number unchanged.

In `tests/Feature/InvoiceTemplateTest.php` add a test that previewing a draft renders the "Draft" label instead of a number (`->assertSee(__('invoice.draft'))`, following the file's existing preview test).

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/InvoiceTest.php tests/Feature/InvoiceStatusMigrationTest.php tests/Feature/InvoiceTemplateTest.php`
Expected: FAIL.

- [ ] **Step 3: Migration** (`php artisan make:migration add_status_to_invoices_table --no-interaction`)

```php
public function up(): void
{
    Schema::table('invoices', function (Blueprint $table) {
        $table->string('number')->nullable()->change();
        $table->string('status', 20)->default('draft')->after('type')->index();
        $table->dateTime('issued_at')->nullable()->after('status');
    });

    DB::table('invoices')->update([
        'status' => 'issued',
        'issued_at' => DB::raw('created_at'),
    ]);
}

public function down(): void
{
    Schema::table('invoices', function (Blueprint $table) {
        $table->dropIndex(['status']);
        $table->dropColumn(['status', 'issued_at']);
    });
}
```

Check the original `number` column type in `2026_08_12_090000_create_invoices_table.php` and keep the same type/length in `->change()`. `down()` does not make `number` NOT NULL again (drafts may exist).

- [ ] **Step 4: Model**

In `Invoice`:
- docblock: `@property string|null $number`, `@property InvoiceStatus $status`, `@property Carbon|null $issued_at`.
- casts: `'status' => InvoiceStatus::class`, `'issued_at' => 'datetime'`.
- `booted()`: remove the number assignment; keep the type default and add `if (! ($invoice->getAttributes()['status'] ?? null)) { $invoice->status = InvoiceStatus::Draft; }`.
- Do **not** add `status`/`issued_at` to `#[Fillable]` (only `IssueInvoice` and the recorder set them).

```php
public function isLocked(): bool
{
    return $this->status === InvoiceStatus::Issued;
}

public function displayNumber(): string
{
    return $this->number ?? __('Draft');
}
```

Factory: `'status' => InvoiceStatus::Draft` in `definition()` and

```php
public function draft(): static
{
    return $this->state(fn (): array => ['status' => InvoiceStatus::Draft, 'issued_at' => null]);
}

/**
 * The number is assigned after making, once `company_id` has been resolved from its factory.
 */
public function issued(): static
{
    return $this->state(fn (): array => ['status' => InvoiceStatus::Issued, 'issued_at' => now()])
        ->afterMaking(function (Invoice $invoice): void {
            $invoice->number ??= Invoice::nextNumber($invoice->company_id);
        });
}
```

- [ ] **Step 5: Requests, controller, PDF, template**

- Remove the `number` rule from `StoreInvoiceRequest` and `UpdateInvoiceRequest`. (`$request->safe()` then never contains it.)
- `InvoiceController::create`: remove `'nextNumber'` prop. `index`: `->orderByRaw('number is null desc')->orderByDesc('number')->orderByDesc('created_at')` so drafts come first.
- `InvoicePdf::filename()`: `return $invoice->number === null ? 'draft-'.$invoice->id.'.pdf' : str_replace(['/', '\\'], '-', $invoice->number).'.pdf';`
- Template: replace `{{ $invoice->number }}` (title and number line) with `{{ $invoice->number ?? __('invoice.draft') }}`; add `'draft' => 'Draft'` / `'Bozza'` / `'Borrador'` to `resources/lang/*/invoice.php`. Add `Draft` to `it.json`/`es.json`.
- `DashboardController`: `'invoice_number' => $invoice->displayNumber()`.
- `SendInvoiceEmailTool` / `InvoiceMail`: grep for `->number` and use `displayNumber()`.

- [ ] **Step 6: Frontend**

`resources/js/lib/invoiceStatus.ts`:

```ts
export type InvoiceStatus = 'draft' | 'issued';

export type SubmissionStatus =
    | 'pending'
    | 'failed'
    | 'submitted'
    | 'rejected'
    | 'accepted'
    | 'delivered'
    | 'not_delivered';

export function invoiceStatusVariant(
    status: InvoiceStatus,
): 'outline' | 'default' {
    return status === 'draft' ? 'outline' : 'default';
}

export function submissionStatusVariant(
    status: SubmissionStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'failed':
        case 'rejected':
            return 'destructive';
        case 'not_delivered':
            return 'secondary';
        case 'pending':
        case 'submitted':
            return 'outline';
        default:
            return 'default';
    }
}
```

- `invoices/Create.vue`: remove the `number` field, `nextNumber` prop and form key; the date input takes the whole first row.
- `invoices/Edit.vue`: remove the editable `number` input and form key; type `number: string | null; status: InvoiceStatus; issued_at: string | null`; heading description uses `invoice.number ?? t('invoices.status.draft')`; email subject/message use the same fallback; show `<Badge :variant="invoiceStatusVariant(invoice.status)">{{ t(`invoices.status.${invoice.status}`) }}</Badge>` next to the action buttons.
- `invoices/Index.vue`: type gains `number: string | null; status: InvoiceStatus`; number cell shows `invoice.number ?? '—'` and a status badge.
- Lang: `invoices.status.draft` (`Draft`/`Bozza`/`Borrador`), `invoices.status.issued` (`Issued`/`Emessa`/`Emitida`).

- [ ] **Step 7: Run tests**

Run: `php artisan test --compact`
Expected: PASS (full suite — numbering changes touch many tests; fix any test that relied on the auto number by using `->issued()`).
Run: `npm run types:check` and `npm run lint:check`.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: draft/issued invoice status with numbering at issue"
```

---

### Task 7: `IssueInvoice` action, locks and Issue button

**Files:**
- Create: `app/Actions/IssueInvoice.php`
- Create: `app/Http/Controllers/InvoiceSubmissionController.php`
- Modify: `routes/invoices.php`, `app/Http/Controllers/InvoiceController.php` (`edit`, `update`, `destroy`), `app/Http/Requests/UpdateInvoiceRequest.php`
- Modify: `resources/js/pages/invoices/Edit.vue`, lang files
- Test: `tests/Feature/IssueInvoiceTest.php`, `tests/Feature/InvoiceLockTest.php`

**Interfaces:**
- Consumes: `CountryComplianceResolver::forCompany()`, `CountryComplianceRules::validateForIssue()/requiresSubmission()`, `Invoice::nextNumber()`, `Invoice::isLocked()`.
- Produces: `IssueInvoice::handle(Invoice $invoice): Invoice` — throws `ValidationException` (keys from `validateForIssue()`, or `invoice` for state errors, or `einvoicing` for missing integration). Route `POST invoices/{invoice}/issue` → `InvoiceSubmissionController@issue` named `invoices.issue`. `InvoiceController::update` accepts only `paid` and `note` when the invoice is locked.

- [ ] **Step 1: Failing tests** `tests/Feature/IssueInvoiceTest.php`

```php
<?php

use App\Actions\IssueInvoice;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

function draftWithRow(array $companyAttributes = []): Invoice
{
    $company = Company::factory()->create(['is_default' => true, 'country_id' => Country::factory()->create(['iso_code' => 'FR']), ...$companyAttributes]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->for($invoice)->create(['vat_rate' => 20]);

    return $invoice;
}

test('issuing a draft in a country without rules assigns number and locks it', function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    $invoice = draftWithRow();

    $issued = app(IssueInvoice::class)->handle($invoice);

    expect($issued->status)->toBe(InvoiceStatus::Issued);
    expect($issued->number)->toBe('2026-0001');
    expect($issued->issued_at->toDateTimeString())->toBe('2026-10-02 10:00:00');
    expect($issued->isLocked())->toBeTrue();

    Carbon::setTestNow();
});

test('issuing keeps an existing number', function () {
    $invoice = draftWithRow();
    $invoice->forceFill(['number' => '2026-0042'])->save();

    expect(app(IssueInvoice::class)->handle($invoice)->number)->toBe('2026-0042');
});

test('issued invoices get consecutive numbers and deleted drafts leave no gaps', function () {
    Carbon::setTestNow('2026-10-02');
    $first = draftWithRow();
    $company = $first->company;
    $deleted = Invoice::factory()->create(['company_id' => $company->id]);
    $second = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->for($second)->create();

    app(IssueInvoice::class)->handle($first);
    $deleted->delete();
    app(IssueInvoice::class)->handle($second);

    expect([$first->fresh()->number, $second->fresh()->number])->toBe(['2026-0001', '2026-0002']);

    Carbon::setTestNow();
});

test('issuing an issued invoice is rejected without a new number', function () {
    $invoice = draftWithRow();
    app(IssueInvoice::class)->handle($invoice);

    expect(fn () => app(IssueInvoice::class)->handle($invoice->fresh()))->toThrow(ValidationException::class);
    expect(Invoice::query()->whereNotNull('number')->count())->toBe(1);
});

test('compliance errors prevent issuing', function () {
    $company = Company::factory()->create(['country_id' => Country::factory()->italy(), 'vat_number' => null]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->for($invoice)->create();

    try {
        app(IssueInvoice::class)->handle($invoice);
        $this->fail('Expected a validation exception');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('company.vat_number');
    }

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
    expect($invoice->fresh()->number)->toBeNull();
});

test('countries that need a submission require an active integration', function () {
    $company = Company::factory()->create([
        'country_id' => Country::factory()->spain(), 'vat_number' => 'B12345678',
    ]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $invoice->customer->update(['vat_number' => 'B87654321', 'country_id' => $company->country_id]);
    InvoiceRow::factory()->for($invoice)->create(['vat_rate' => 21]);

    expect(fn () => app(IssueInvoice::class)->handle($invoice))
        ->toThrow(ValidationException::class, __('Configure electronic invoicing for this company before issuing invoices.'));
});

test('issue route issues the current company invoice', function () {
    $invoice = draftWithRow();

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.issue', $invoice))
        ->assertRedirect(route('invoices.edit', $invoice));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
});

test('issue route returns errors to the page', function () {
    $invoice = draftWithRow();
    $invoice->rows()->delete();

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.issue', $invoice))
        ->assertSessionHasErrors('rows');
});

test('issue route refuses invoices of another company', function () {
    $invoice = draftWithRow();
    Company::query()->update(['is_default' => false]);
    Company::factory()->create(['is_default' => true]);

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.issue', $invoice))
        ->assertForbidden();
});
```

(The `Invoice::submissions()` relation arrives in Task 8; Task 9 adds a "no submission was created" assertion to the first test.)

`tests/Feature/InvoiceLockTest.php`:

```php
<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;

beforeEach(function () {
    $this->company = Company::factory()->create(['is_default' => true]);
    $this->invoice = Invoice::factory()->issued()->create(['company_id' => $this->company->id, 'paid' => false, 'note' => null]);
    $this->row = InvoiceRow::factory()->for($this->invoice)->create(['description' => 'Original', 'price' => 10]);
    $this->actingAs(User::factory()->create());
});

test('issued invoices cannot be deleted', function () {
    $this->delete(route('invoices.destroy', $this->invoice))->assertRedirect();

    expect(Invoice::query()->whereKey($this->invoice->id)->exists())->toBeTrue();
});

test('issued invoices only accept paid and note changes', function () {
    $this->put(route('invoices.update', $this->invoice), [
        'invoice_date' => '2020-01-01',
        'paid' => true,
        'note' => 'Paid by bank transfer',
        'customer_id' => $this->invoice->customer_id,
        'language' => $this->invoice->language,
        'rows' => [['id' => $this->row->id, 'description' => 'Changed', 'quantity' => 1, 'price' => 999, 'vat_rate' => 0]],
    ])->assertRedirect();

    $invoice = $this->invoice->fresh();
    expect($invoice->paid)->toBeTrue();
    expect($invoice->note)->toBe('Paid by bank transfer');
    expect($invoice->invoice_date->format('Y-m-d'))->not->toBe('2020-01-01');
    expect($this->row->fresh()->description)->toBe('Original');
    expect((float) $this->row->fresh()->price)->toBe(10.0);
});

test('drafts remain editable and deletable', function () {
    $draft = Invoice::factory()->create(['company_id' => $this->company->id]);

    $this->delete(route('invoices.destroy', $draft))->assertRedirect(route('invoices.index'));

    expect(Invoice::query()->whereKey($draft->id)->exists())->toBeFalse();
});

test('edit page exposes the lock', function () {
    $this->get(route('invoices.edit', $this->invoice))
        ->assertInertia(fn ($page) => $page->where('invoice.status', 'issued')->where('isLocked', true));
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/IssueInvoiceTest.php tests/Feature/InvoiceLockTest.php`
Expected: FAIL.

- [ ] **Step 3: `app/Actions/IssueInvoice.php`** (`php artisan make:class Actions/IssueInvoice --no-interaction`)

```php
<?php

namespace App\Actions;

use App\EInvoicing\Contracts\CountryComplianceRules;
use App\EInvoicing\CountryComplianceResolver;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueInvoice
{
    public function handle(Invoice $invoice): Invoice
    {
        $invoice->load(['company.country', 'customer.country', 'rows']);

        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages(['invoice' => __('Only draft invoices can be issued.')]);
        }

        $rules = CountryComplianceResolver::forCompany($invoice->company);

        $errors = $rules->validateForIssue($invoice);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->ensureSubmissionIsPossible($invoice->company, $rules);

        DB::transaction(function () use ($invoice): void {
            Company::query()->whereKey($invoice->company_id)->lockForUpdate()->first();

            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceStatus::Draft) {
                throw ValidationException::withMessages(['invoice' => __('Only draft invoices can be issued.')]);
            }

            $invoice->number ??= Invoice::nextNumber($invoice->company_id);
            $invoice->status = InvoiceStatus::Issued;
            $invoice->issued_at = now();
            $invoice->save();
        });

        return $invoice;
    }

    protected function ensureSubmissionIsPossible(Company $company, CountryComplianceRules $rules): void
    {
        if ($rules->requiresSubmission()) {
            throw ValidationException::withMessages([
                'einvoicing' => __('Configure electronic invoicing for this company before issuing invoices.'),
            ]);
        }
    }
}
```

(`ensureSubmissionIsPossible` and the transaction body are extended in Task 9.)

- [ ] **Step 4: Controller and route**

`app/Http/Controllers/InvoiceSubmissionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\IssueInvoice;
use App\Http\Controllers\Concerns\ScopesToCurrentCompany;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class InvoiceSubmissionController extends Controller
{
    use ScopesToCurrentCompany;

    public function issue(Invoice $invoice, IssueInvoice $issueInvoice): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoice);

        $issueInvoice->handle($invoice);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice issued.')]);

        return to_route('invoices.edit', $invoice);
    }
}
```

`routes/invoices.php`: `Route::post('invoices/{invoice}/issue', [InvoiceSubmissionController::class, 'issue'])->name('invoices.issue');` (before the resource line). The thrown `ValidationException` redirects back with errors automatically.

- [ ] **Step 5: Locks in `InvoiceController`**

`edit`: add prop `'isLocked' => $invoice->isLocked()`.

`update`: at the top, after authorisation:

```php
if ($invoice->isLocked()) {
    $invoice->update($request->safe()->only(['paid', 'note']));

    Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice updated.')]);

    return to_route('invoices.edit', $invoice);
}
```

`destroy`: before deleting:

```php
if ($invoice->isLocked()) {
    Inertia::flash('toast', ['type' => 'error', 'message' => __('An issued invoice cannot be deleted.')]);

    return to_route('invoices.edit', $invoice);
}
```

- [ ] **Step 6: Edit page**

`invoices/Edit.vue`:
- prop `isLocked: boolean`.
- "Issue" button (when `invoice.status === 'draft'`) next to the PDF/email buttons:

```ts
const issueForm = useForm({});

async function onIssue(): Promise<void> {
    if (await confirmDialog(t('invoices.edit.confirmIssue'))) {
        issueForm.post(InvoiceSubmissionController.issue(props.invoice.id).url, { preserveScroll: true });
    }
}
```

  (import `InvoiceSubmissionController` from `@/actions/App/Http/Controllers/InvoiceSubmissionController`.)
- Show page-level issue errors above the form: `<AlertError v-if="Object.keys(issueForm.errors).length" :errors="Object.values(issueForm.errors)" />` (check `AlertError.vue` props and adapt). Field paths like `customer.vat_number` are not form fields, so the list display is the inline feedback.
- When `isLocked`: wrap every input except `paid` and `note` in `:disabled="isLocked"` (inputs, selects, checkboxes, ProductPicker, add/remove row buttons); hide the delete section (`v-if="!isLocked"`).
- Lang: `invoices.edit.issueButton` (`Issue` / `Emetti` / `Emitir`), `invoices.edit.confirmIssue` (`Once issued, the invoice gets its number and can no longer be changed. Continue?` + it/es), `invoices.edit.lockedNotice` (`This invoice has been issued: only the paid flag and the note can be changed.` + it/es) shown when locked. Add `Invoice issued.`, `Only draft invoices can be issued.`, `An issued invoice cannot be deleted.`, `Configure electronic invoicing for this company before issuing invoices.`, `The invoice must have at least one row.` to `it.json`/`es.json`.

- [ ] **Step 7: Run tests**

Run: `php artisan test --compact tests/Feature/IssueInvoiceTest.php tests/Feature/InvoiceLockTest.php tests/Feature/InvoiceTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
php artisan wayfinder:generate
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: issue invoices and lock issued invoices"
```

---

## Phase 3 — Providers, submissions, webhooks, polling

### Task 8: E-invoicing core (enums, DTOs, contract, models, FakeProvider, factory)

**Files:**
- Create: `app/EInvoicing/Enums/{EInvoicingDriver,EInvoicingEnvironment,Capability}.php`
- Create: `app/EInvoicing/Data/{SubmissionResult,ProviderNotification}.php`
- Create: `app/EInvoicing/Contracts/EInvoicingProvider.php`
- Create: `app/EInvoicing/Exceptions/{TransientProviderException,InvalidWebhookSignature}.php`
- Create: `app/EInvoicing/Providers/FakeProvider.php`, `app/EInvoicing/EInvoicingProviderFactory.php`
- Create: models + factories + one migration for `e_invoicing_integrations`, `invoice_submissions`, `invoice_submission_events`
- Modify: `app/Models/Company.php`, `app/Models/Invoice.php`, `tests/TestCase.php`
- Test: `tests/Feature/EInvoicing/EInvoicingCoreTest.php`

**Interfaces:**
- Produces:
  - `enum EInvoicingDriver: string { B2Brouter='b2brouter'; Fake='fake' }` with `providerClass(): class-string<EInvoicingProvider>`, `label(): string`, `credentialRules(): array<string, array<mixed>>` (keys without prefix), `credentialFields(): list<array{name: string, type: 'text'|'password', required: bool}>`, `supportedCountries(): list<string>`, `isAvailable(): bool`, `static availableFor(?string $isoCode): list<self>`.
  - `enum EInvoicingEnvironment: string { Sandbox='sandbox'; Staging='staging'; Production='production' }`.
  - `enum Capability: string { ItalySdi='italy_sdi'; SpainVerifactu='spain_verifactu'; ReceivePassiveInvoices='receive_passive_invoices'; LegalArchiving='legal_archiving'; PublicAdministration='public_administration' }`.
  - `final readonly class SubmissionResult(SubmissionStatus $status, ?string $providerStatus = null, ?string $externalId = null, ?string $authorityId = null, ?string $qrCode = null, ?string $errorMessage = null, ?string $document = null)` + `static failed(string $message, ?string $document = null): self`.
  - `final readonly class ProviderNotification(string $eventId, string $externalId, string $type, array $payload, ?SubmissionResult $result = null)` — `result === null` means "fetch the status from the provider".
  - Provider constructor convention: `__construct(array $credentials, EInvoicingEnvironment $environment)`.
  - `EInvoicingProviderFactory::forIntegration(EInvoicingIntegration $integration): EInvoicingProvider`.
  - `FakeProvider::reset()`, `::queueSendResult(SubmissionResult|Throwable)`, `::queueStatusResult(SubmissionResult|Throwable)`, `::$connectionSucceeds` (bool), `::$sentInvoiceIds` (list<string>). Webhook: header `X-Fake-Secret` must equal `integration.webhook_secret`; JSON body `{event_id, external_id, status, provider_status?}`.
  - Models: `EInvoicingIntegration` (`company()`, `submissions()`, `supportsCountry(?string): bool`, `credentials` hidden + `encrypted:array`, `webhook_secret` hidden, auto-generated), `InvoiceSubmission` (`invoice()`, `integration()`, `events()`), `InvoiceSubmissionEvent` (`submission()`), `Company::eInvoicingIntegration(): HasOne`, `Invoice::submissions(): HasMany`, `Invoice::latestSubmission(): HasOne`.

- [ ] **Step 1: Failing tests** `tests/Feature/EInvoicing/EInvoicingCoreTest.php`

```php
<?php

use App\EInvoicing\EInvoicingProviderFactory;
use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Providers\FakeProvider;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('integration credentials are encrypted at rest and hidden', function () {
    $integration = EInvoicingIntegration::factory()->create(['credentials' => ['api_key' => 'secret-key']]);

    expect(DB::table('e_invoicing_integrations')->value('credentials'))->not->toContain('secret-key');
    expect($integration->fresh()->credentials)->toBe(['api_key' => 'secret-key']);
    expect($integration->toArray())->not->toHaveKeys(['credentials', 'webhook_secret']);
    expect($integration->webhook_secret)->toHaveLength(40);
});

test('one integration per company', function () {
    $integration = EInvoicingIntegration::factory()->create();

    expect(fn () => EInvoicingIntegration::factory()->create(['company_id' => $integration->company_id]))
        ->toThrow(QueryException::class);
});

test('drivers expose availability and countries', function () {
    expect(EInvoicingDriver::B2Brouter->supportedCountries())->toBe(['IT', 'ES']);
    expect(EInvoicingDriver::Fake->isAvailable())->toBeTrue();
    expect(EInvoicingDriver::availableFor('IT'))->toContain(EInvoicingDriver::B2Brouter, EInvoicingDriver::Fake);
    expect(EInvoicingDriver::availableFor('FR'))->toBe([]);
    expect(array_column(EInvoicingDriver::B2Brouter->credentialFields(), 'name'))->toContain('api_key', 'account_id');
});

test('the fake driver is unavailable in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(EInvoicingDriver::Fake->isAvailable())->toBeFalse();
    expect(fn () => app(EInvoicingProviderFactory::class)->forIntegration(EInvoicingIntegration::factory()->make()))
        ->toThrow(InvalidArgumentException::class);
});

test('factory builds the configured provider', function () {
    $provider = app(EInvoicingProviderFactory::class)->forIntegration(EInvoicingIntegration::factory()->create());

    expect($provider)->toBeInstanceOf(FakeProvider::class);
});

test('invoice latest submission is the most recent one', function () {
    $invoice = Invoice::factory()->issued()->create();
    InvoiceSubmission::factory()->for($invoice)->create(['status' => SubmissionStatus::Failed, 'created_at' => now()->subMinute()]);
    $latest = InvoiceSubmission::factory()->for($invoice)->create(['status' => SubmissionStatus::Submitted]);

    expect($invoice->latestSubmission->id)->toBe($latest->id);
});

test('provider event ids are unique', function () {
    $submission = InvoiceSubmission::factory()->create();
    $submission->events()->create(['type' => 'x', 'provider_event_id' => 'evt-1', 'payload' => [], 'received_at' => now()]);

    expect(fn () => $submission->events()->create(['type' => 'x', 'provider_event_id' => 'evt-1', 'payload' => [], 'received_at' => now()]))
        ->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/EInvoicing/EInvoicingCoreTest.php`
Expected: FAIL.

- [ ] **Step 3: Migration** (`php artisan make:migration create_e_invoicing_tables --no-interaction`)

```php
public function up(): void
{
    Schema::create('e_invoicing_integrations', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->foreignUuid('company_id')->unique()->constrained()->cascadeOnDelete();
        $table->string('driver', 30);
        $table->string('environment', 20);
        $table->text('credentials')->nullable();
        $table->string('webhook_secret', 64);
        $table->boolean('is_active')->default(false);
        $table->timestamps();
    });

    Schema::create('invoice_submissions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
        $table->foreignUuid('e_invoicing_integration_id')->constrained()->restrictOnDelete();
        $table->string('driver', 30);
        $table->string('status', 20)->index();
        $table->string('provider_status')->nullable();
        $table->string('external_id')->nullable()->index();
        $table->string('authority_id')->nullable();
        $table->text('qr_code')->nullable();
        $table->text('error_message')->nullable();
        $table->string('payload_path')->nullable();
        $table->dateTime('submitted_at')->nullable();
        $table->dateTime('completed_at')->nullable();
        $table->timestamps();
    });

    Schema::create('invoice_submission_events', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->foreignUuid('submission_id')->constrained('invoice_submissions')->cascadeOnDelete();
        $table->string('type');
        $table->string('provider_event_id')->unique();
        $table->json('payload');
        $table->dateTime('received_at');
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('invoice_submission_events');
    Schema::dropIfExists('invoice_submissions');
    Schema::dropIfExists('e_invoicing_integrations');
}
```

(Check how existing migrations reference uuid FKs, e.g. `add_company_id_to_invoices_table`, and match that style.)

- [ ] **Step 4: Enums**

```php
<?php

namespace App\EInvoicing\Enums;

use App\EInvoicing\Contracts\EInvoicingProvider;
use App\EInvoicing\Providers\B2BrouterProvider;
use App\EInvoicing\Providers\FakeProvider;

enum EInvoicingDriver: string
{
    case B2Brouter = 'b2brouter';
    case Fake = 'fake';

    /**
     * @return class-string<EInvoicingProvider>
     */
    public function providerClass(): string
    {
        return match ($this) {
            self::B2Brouter => B2BrouterProvider::class,
            self::Fake => FakeProvider::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::B2Brouter => 'B2Brouter',
            self::Fake => 'Fake (local)',
        };
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function credentialRules(): array
    {
        return match ($this) {
            self::B2Brouter => [
                'api_key' => ['required', 'string', 'max:255'],
                'account_id' => ['required', 'string', 'max:255'],
                'webhook_signing_secret' => ['nullable', 'string', 'max:255'],
            ],
            self::Fake => [],
        };
    }

    /**
     * @return list<array{name: string, type: 'text'|'password', required: bool}>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::B2Brouter => [
                ['name' => 'api_key', 'type' => 'password', 'required' => true],
                ['name' => 'account_id', 'type' => 'text', 'required' => true],
                ['name' => 'webhook_signing_secret', 'type' => 'password', 'required' => false],
            ],
            self::Fake => [],
        };
    }

    /**
     * @return list<string>
     */
    public function supportedCountries(): array
    {
        return ['IT', 'ES'];
    }

    public function isAvailable(): bool
    {
        return $this !== self::Fake || app()->environment(['local', 'testing']);
    }

    /**
     * @return list<self>
     */
    public static function availableFor(?string $isoCode): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $driver): bool => $driver->isAvailable() && in_array($isoCode, $driver->supportedCountries(), true),
        ));
    }
}
```

`EInvoicingEnvironment` and `Capability`: string-backed, cases as in the Interfaces block, no methods.

- [ ] **Step 5: DTOs, exceptions, contract**

```php
<?php

namespace App\EInvoicing\Data;

use App\EInvoicing\Enums\SubmissionStatus;

final readonly class SubmissionResult
{
    public function __construct(
        public SubmissionStatus $status,
        public ?string $providerStatus = null,
        public ?string $externalId = null,
        public ?string $authorityId = null,
        public ?string $qrCode = null,
        public ?string $errorMessage = null,
        public ?string $document = null,
    ) {}

    public static function failed(string $message, ?string $document = null): self
    {
        return new self(SubmissionStatus::Failed, errorMessage: $message, document: $document);
    }
}
```

```php
<?php

namespace App\EInvoicing\Data;

final readonly class ProviderNotification
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $externalId,
        public string $type,
        public array $payload,
        public ?SubmissionResult $result = null,
    ) {}
}
```

`app/EInvoicing/Exceptions/TransientProviderException.php`: `class TransientProviderException extends RuntimeException {}`; `InvalidWebhookSignature.php`: `class InvalidWebhookSignature extends RuntimeException {}`.

```php
<?php

namespace App\EInvoicing\Contracts;

use App\EInvoicing\Data\ProviderNotification;
use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\Capability;
use App\EInvoicing\Exceptions\InvalidWebhookSignature;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Http\Request;

interface EInvoicingProvider
{
    /**
     * @throws TransientProviderException on timeouts, 5xx and 429
     */
    public function send(Invoice $invoice): SubmissionResult;

    /**
     * @throws TransientProviderException
     */
    public function fetchStatus(InvoiceSubmission $submission): SubmissionResult;

    /**
     * Verify the signature and normalise the notification; null when not relevant.
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(Request $request, EInvoicingIntegration $integration): ?ProviderNotification;

    public function testConnection(): bool;

    /**
     * @return list<Capability>
     */
    public function capabilities(): array;
}
```

- [ ] **Step 6: Models and factories**

Run: `php artisan make:model EInvoicingIntegration --factory --no-interaction`, same for `InvoiceSubmission` and `InvoiceSubmissionEvent`.

`EInvoicingIntegration`:

```php
/**
 * @property string $id
 * @property string $company_id
 * @property EInvoicingDriver $driver
 * @property EInvoicingEnvironment $environment
 * @property array<string, string>|null $credentials
 * @property string $webhook_secret
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['company_id', 'driver', 'environment', 'credentials', 'is_active'])]
#[Hidden(['credentials', 'webhook_secret'])]
class EInvoicingIntegration extends Model
{
    /** @use HasFactory<EInvoicingIntegrationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => EInvoicingDriver::class,
            'environment' => EInvoicingEnvironment::class,
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EInvoicingIntegration $integration): void {
            $integration->webhook_secret ??= Str::random(40);
        });
    }

    public function supportsCountry(?string $isoCode): bool
    {
        return $this->driver->isAvailable() && in_array($isoCode, $this->driver->supportedCountries(), true);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<InvoiceSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(InvoiceSubmission::class);
    }
}
```

(Check that `#[Hidden]` exists in `Illuminate\Database\Eloquent\Attributes` for this Laravel version; otherwise use `protected $hidden = [...]`.)

`InvoiceSubmission`: `#[Fillable(['invoice_id', 'e_invoicing_integration_id', 'driver', 'status', 'provider_status', 'external_id', 'authority_id', 'qr_code', 'error_message', 'payload_path', 'submitted_at', 'completed_at'])]`; casts `driver` → `EInvoicingDriver::class`, `status` → `SubmissionStatus::class`, `submitted_at`/`completed_at` → `datetime`; docblock properties for every column. Relations:

```php
/** @return BelongsTo<Invoice, $this> */
public function invoice(): BelongsTo
{
    return $this->belongsTo(Invoice::class);
}

/** @return BelongsTo<EInvoicingIntegration, $this> */
public function integration(): BelongsTo
{
    return $this->belongsTo(EInvoicingIntegration::class, 'e_invoicing_integration_id');
}

/** @return HasMany<InvoiceSubmissionEvent, $this> */
public function events(): HasMany
{
    return $this->hasMany(InvoiceSubmissionEvent::class, 'submission_id')->latest('received_at');
}
```

`InvoiceSubmissionEvent`: `#[Fillable(['submission_id', 'type', 'provider_event_id', 'payload', 'received_at'])]`; casts `payload` → `array`, `received_at` → `datetime`; `submission(): BelongsTo` (`belongsTo(InvoiceSubmission::class, 'submission_id')`).

`Company`:

```php
/**
 * @return HasOne<EInvoicingIntegration, $this>
 */
public function eInvoicingIntegration(): HasOne
{
    return $this->hasOne(EInvoicingIntegration::class);
}
```

`Invoice`:

```php
/**
 * @return HasMany<InvoiceSubmission, $this>
 */
public function submissions(): HasMany
{
    return $this->hasMany(InvoiceSubmission::class);
}

/**
 * @return HasOne<InvoiceSubmission, $this>
 */
public function latestSubmission(): HasOne
{
    return $this->hasOne(InvoiceSubmission::class)->latestOfMany(['created_at' => 'max', 'id' => 'max']);
}
```

Factories:

```php
// EInvoicingIntegrationFactory::definition()
return [
    'company_id' => Company::factory(),
    'driver' => EInvoicingDriver::Fake,
    'environment' => EInvoicingEnvironment::Sandbox,
    'credentials' => [],
    'is_active' => true,
];
```

```php
// InvoiceSubmissionFactory::definition()
return [
    'invoice_id' => Invoice::factory()->issued(),
    'e_invoicing_integration_id' => fn (array $attributes) => EInvoicingIntegration::query()->firstOrCreate(
        ['company_id' => Invoice::query()->whereKey($attributes['invoice_id'])->value('company_id')],
        ['driver' => EInvoicingDriver::Fake, 'environment' => EInvoicingEnvironment::Sandbox, 'credentials' => [], 'is_active' => true],
    )->id,
    'driver' => EInvoicingDriver::Fake,
    'status' => SubmissionStatus::Submitted,
    'external_id' => 'fake-'.fake()->uuid(),
    'submitted_at' => now(),
];
```

- [ ] **Step 7: FakeProvider, factory, placeholder B2Brouter class, TestCase reset**

```php
<?php

namespace App\EInvoicing\Providers;

use App\EInvoicing\Contracts\EInvoicingProvider;
use App\EInvoicing\Data\ProviderNotification;
use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\Capability;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Exceptions\InvalidWebhookSignature;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * In-memory provider for local development and tests.
 */
class FakeProvider implements EInvoicingProvider
{
    /** @var list<SubmissionResult|Throwable> */
    private static array $sendResults = [];

    /** @var list<SubmissionResult|Throwable> */
    private static array $statusResults = [];

    public static bool $connectionSucceeds = true;

    /** @var list<string> */
    public static array $sentInvoiceIds = [];

    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(public array $credentials = [], public EInvoicingEnvironment $environment = EInvoicingEnvironment::Sandbox) {}

    public static function reset(): void
    {
        self::$sendResults = [];
        self::$statusResults = [];
        self::$connectionSucceeds = true;
        self::$sentInvoiceIds = [];
    }

    public static function queueSendResult(SubmissionResult|Throwable $result): void
    {
        self::$sendResults[] = $result;
    }

    public static function queueStatusResult(SubmissionResult|Throwable $result): void
    {
        self::$statusResults[] = $result;
    }

    public function send(Invoice $invoice): SubmissionResult
    {
        self::$sentInvoiceIds[] = $invoice->id;

        return $this->unwrap(array_shift(self::$sendResults))
            ?? new SubmissionResult(SubmissionStatus::Submitted, 'submitted', 'fake-'.Str::uuid());
    }

    public function fetchStatus(InvoiceSubmission $submission): SubmissionResult
    {
        return $this->unwrap(array_shift(self::$statusResults))
            ?? new SubmissionResult($submission->status, $submission->provider_status, $submission->external_id);
    }

    public function parseWebhook(Request $request, EInvoicingIntegration $integration): ?ProviderNotification
    {
        if (! hash_equals($integration->webhook_secret, (string) $request->header('X-Fake-Secret'))) {
            throw new InvalidWebhookSignature('Invalid fake webhook secret.');
        }

        $status = SubmissionStatus::tryFrom((string) $request->input('status'));

        if (! $request->filled(['event_id', 'external_id']) || $status === null) {
            return null;
        }

        return new ProviderNotification(
            eventId: $request->string('event_id')->toString(),
            externalId: $request->string('external_id')->toString(),
            type: 'status_change',
            payload: $request->all(),
            result: new SubmissionResult($status, $request->input('provider_status'), $request->string('external_id')->toString()),
        );
    }

    public function testConnection(): bool
    {
        return self::$connectionSucceeds;
    }

    public function capabilities(): array
    {
        return [Capability::ItalySdi, Capability::SpainVerifactu];
    }

    private function unwrap(SubmissionResult|Throwable|null $result): ?SubmissionResult
    {
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
```

```php
<?php

namespace App\EInvoicing;

use App\EInvoicing\Contracts\EInvoicingProvider;
use App\Models\EInvoicingIntegration;
use InvalidArgumentException;

class EInvoicingProviderFactory
{
    public function forIntegration(EInvoicingIntegration $integration): EInvoicingProvider
    {
        if (! $integration->driver->isAvailable()) {
            throw new InvalidArgumentException("The [{$integration->driver->value}] e-invoicing driver is not available.");
        }

        return app()->makeWith($integration->driver->providerClass(), [
            'credentials' => $integration->credentials ?? [],
            'environment' => $integration->environment,
        ]);
    }
}
```

`app/EInvoicing/Providers/B2BrouterProvider.php`: for now a class implementing `EInvoicingProvider` with constructor `(private array $credentials, private EInvoicingEnvironment $environment)` whose methods throw `new LogicException('B2Brouter driver not implemented yet.')` (`capabilities()` returns `[]`). Task 12 replaces it.

`tests/TestCase.php`: add

```php
protected function setUp(): void
{
    parent::setUp();

    FakeProvider::reset();
}
```

- [ ] **Step 8: Run tests**

Run: `php artisan test --compact tests/Feature/EInvoicing`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: e-invoicing core contracts, models and fake provider"
```

---

### Task 9: `SubmissionResultRecorder`, `SubmitInvoice` job, submission on issue

**Files:**
- Create: `app/EInvoicing/SubmissionResultRecorder.php`, `app/Jobs/SubmitInvoice.php`
- Modify: `app/Actions/IssueInvoice.php`, `app/Http/Controllers/InvoiceSubmissionController.php`
- Test: `tests/Feature/EInvoicing/SubmissionResultRecorderTest.php`, `tests/Feature/EInvoicing/SubmitInvoiceTest.php`, `tests/Feature/IssueInvoiceTest.php`

**Interfaces:**
- Consumes: Task 8 models/DTOs/factory, `CountryComplianceResolver`.
- Produces:
  - `SubmissionResultRecorder::record(InvoiceSubmission $submission, SubmissionResult $result): InvoiceSubmission` — writes the fields (keeps existing non-null `external_id`/`authority_id`/`qr_code`/`provider_status` when the result has null), stores `document` at `einvoicing/{submission id}/{YmdHisv}.txt` on the `local` disk, sets `submitted_at` on the first status that is neither `Pending` nor `Failed`, sets `completed_at` on final statuses, **ignores** a non-final result when the submission is already final, and puts the invoice back to `Draft` (keeping its number, `issued_at = null`) when `allowsRevisionAfter()` allows it.
  - `SubmitInvoice` job (constructor `InvoiceSubmission $submission`), `tries = 3`, `backoff() = [60, 300, 900]`, unique by submission id.
  - `IssueInvoice` creates a `Pending` submission and dispatches `SubmitInvoice` after the commit.

- [ ] **Step 1: Failing tests**

`tests/Feature/EInvoicing/SubmissionResultRecorderTest.php`:

```php
<?php

use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\SubmissionResultRecorder;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Support\Facades\Storage;

function submissionFor(string $isoCode, SubmissionStatus $status = SubmissionStatus::Submitted): InvoiceSubmission
{
    $company = Company::factory()->create(['country_id' => Country::factory()->create(['iso_code' => $isoCode])]);
    $invoice = Invoice::factory()->issued()->create(['company_id' => $company->id, 'number' => '2026-0007']);

    return InvoiceSubmission::factory()->for($invoice)->create(['status' => $status, 'qr_code' => null]);
}

test('final results complete the submission', function () {
    $submission = submissionFor('IT');

    app(SubmissionResultRecorder::class)->record($submission, new SubmissionResult(SubmissionStatus::Delivered, 'RC', authorityId: '12345'));

    $submission->refresh();
    expect($submission->status)->toBe(SubmissionStatus::Delivered);
    expect($submission->authority_id)->toBe('12345');
    expect($submission->completed_at)->not->toBeNull();
    expect($submission->invoice->status)->toBe(InvoiceStatus::Issued);
});

test('italian rejection returns the invoice to draft keeping the number', function () {
    $submission = submissionFor('IT');

    app(SubmissionResultRecorder::class)->record($submission, new SubmissionResult(SubmissionStatus::Rejected, 'NS', errorMessage: '00404'));

    $invoice = $submission->invoice->fresh();
    expect($invoice->status)->toBe(InvoiceStatus::Draft);
    expect($invoice->number)->toBe('2026-0007');
});

test('spanish rejection keeps the invoice issued', function () {
    $submission = submissionFor('ES');

    app(SubmissionResultRecorder::class)->record($submission, new SubmissionResult(SubmissionStatus::Rejected));

    expect($submission->invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
});

test('failure returns the invoice to draft in both countries', function (string $isoCode) {
    $submission = submissionFor($isoCode, SubmissionStatus::Pending);

    app(SubmissionResultRecorder::class)->record($submission, SubmissionResult::failed('Invalid credentials'));

    expect($submission->fresh()->error_message)->toBe('Invalid credentials');
    expect($submission->invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
})->with(['IT', 'ES']);

test('a late non-final result does not reopen a final submission', function () {
    $submission = submissionFor('ES', SubmissionStatus::Accepted);

    app(SubmissionResultRecorder::class)->record($submission, new SubmissionResult(SubmissionStatus::Submitted));

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Accepted);
});

test('known values are not erased and documents are stored', function () {
    Storage::fake('local');
    $submission = submissionFor('ES');
    $submission->update(['qr_code' => 'QR', 'external_id' => 'ext-1']);

    app(SubmissionResultRecorder::class)->record($submission, new SubmissionResult(SubmissionStatus::Accepted, document: '{"ok":true}'));

    $submission->refresh();
    expect($submission->qr_code)->toBe('QR');
    expect($submission->external_id)->toBe('ext-1');
    Storage::disk('local')->assertExists($submission->payload_path);
});
```

`tests/Feature/EInvoicing/SubmitInvoiceTest.php`:

```php
<?php

use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\EInvoicingProviderFactory;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\EInvoicing\Providers\FakeProvider;
use App\EInvoicing\SubmissionResultRecorder;
use App\Enums\InvoiceStatus;
use App\Jobs\SubmitInvoice;
use App\Models\Company;
use App\Models\Country;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Contracts\Queue\ShouldBeUnique;

function pendingSubmission(): InvoiceSubmission
{
    $company = Company::factory()->create(['country_id' => Country::factory()->italy()]);
    $invoice = Invoice::factory()->issued()->create(['company_id' => $company->id, 'number' => '2026-0003']);

    return InvoiceSubmission::factory()->for($invoice)->create(['status' => SubmissionStatus::Pending, 'external_id' => null, 'submitted_at' => null]);
}

test('a successful send marks the submission submitted', function () {
    $submission = pendingSubmission();

    SubmitInvoice::dispatchSync($submission);

    $submission->refresh();
    expect($submission->status)->toBe(SubmissionStatus::Submitted);
    expect($submission->external_id)->toStartWith('fake-');
    expect($submission->submitted_at)->not->toBeNull();
});

test('transient errors are retried with backoff', function () {
    FakeProvider::queueSendResult(new TransientProviderException('503'));
    $job = (new SubmitInvoice(pendingSubmission()))->withFakeQueueInteractions();

    $job->handle(app(EInvoicingProviderFactory::class), app(SubmissionResultRecorder::class));

    $job->assertReleased(delay: 60);
    expect($job->submission->fresh()->status)->toBe(SubmissionStatus::Pending);
});

test('transient errors on the sync queue fail the submission and keep the number', function () {
    FakeProvider::queueSendResult(new TransientProviderException('timeout'));
    $submission = pendingSubmission();

    SubmitInvoice::dispatchSync($submission);

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Failed);
    expect($submission->invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
    expect($submission->invoice->fresh()->number)->toBe('2026-0003');
});

test('definitive errors fail immediately with the provider message', function () {
    FakeProvider::queueSendResult(SubmissionResult::failed('401 Unauthorized'));
    $submission = pendingSubmission();

    SubmitInvoice::dispatchSync($submission);

    expect($submission->fresh()->error_message)->toBe('401 Unauthorized');
    expect($submission->invoice->fresh()->number)->toBe('2026-0003');
});

test('the job is unique per submission and skips non pending submissions', function () {
    $submission = pendingSubmission();
    $job = new SubmitInvoice($submission);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe($submission->id);

    $submission->update(['status' => SubmissionStatus::Submitted]);
    SubmitInvoice::dispatchSync($submission);
    expect(FakeProvider::$sentInvoiceIds)->toBe([]);
});
```

Append to `tests/Feature/IssueInvoiceTest.php` (add the imports `App\EInvoicing\Data\SubmissionResult`, `App\EInvoicing\Enums\SubmissionStatus`, `App\EInvoicing\Providers\FakeProvider`, `App\Models\EInvoicingIntegration`):

```php
function spanishDraft(): Invoice
{
    $spain = Country::factory()->spain()->create();
    $company = Company::factory()->create(['is_default' => true, 'country_id' => $spain->id, 'vat_number' => 'B12345678']);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $invoice->customer->update(['vat_number' => 'B87654321', 'country_id' => $spain->id]);
    InvoiceRow::factory()->for($invoice)->create(['vat_rate' => 21]);

    return $invoice;
}

test('issuing with an active integration creates and sends a submission', function () {
    $invoice = spanishDraft();
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id]);

    app(IssueInvoice::class)->handle($invoice);

    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Submitted);
    expect(FakeProvider::$sentInvoiceIds)->toBe([$invoice->id]);
});

test('an inactive integration is not enough', function () {
    $invoice = spanishDraft();
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id, 'is_active' => false]);

    expect(fn () => app(IssueInvoice::class)->handle($invoice))->toThrow(ValidationException::class);
});

test('a failed submission can be retried by issuing again with the same number', function () {
    $invoice = spanishDraft();
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id]);
    FakeProvider::queueSendResult(SubmissionResult::failed('Bad payload'));

    app(IssueInvoice::class)->handle($invoice);
    $number = $invoice->fresh()->number;
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);

    app(IssueInvoice::class)->handle($invoice->fresh());

    expect($invoice->fresh()->number)->toBe($number);
    expect($invoice->submissions()->count())->toBe(2);
    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Submitted);
});

test('issue route reports a failed synchronous submission', function () {
    $invoice = spanishDraft();
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id]);
    FakeProvider::queueSendResult(SubmissionResult::failed('Bad payload'));

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.issue', $invoice))
        ->assertRedirect(route('invoices.edit', $invoice));

    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Failed);
    // and assert the error toast: grep `toast` in tests/Feature to reuse the existing assertion technique for Inertia::flash
});
```

Also add `expect($issued->submissions()->count())->toBe(0);` to the first test of the file ("issuing a draft in a country without rules…").

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/EInvoicing tests/Feature/IssueInvoiceTest.php`
Expected: FAIL.

- [ ] **Step 3: Recorder** `app/EInvoicing/SubmissionResultRecorder.php`

```php
<?php

namespace App\EInvoicing;

use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\SubmissionStatus;
use App\Enums\InvoiceStatus;
use App\Models\InvoiceSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SubmissionResultRecorder
{
    public function record(InvoiceSubmission $submission, SubmissionResult $result): InvoiceSubmission
    {
        if ($submission->status->isFinal() && ! $result->status->isFinal()) {
            return $submission;
        }

        DB::transaction(function () use ($submission, $result): void {
            $submission->fill([
                'status' => $result->status,
                'provider_status' => $result->providerStatus ?? $submission->provider_status,
                'external_id' => $result->externalId ?? $submission->external_id,
                'authority_id' => $result->authorityId ?? $submission->authority_id,
                'qr_code' => $result->qrCode ?? $submission->qr_code,
                'error_message' => $result->errorMessage,
            ]);

            if ($result->document !== null) {
                $path = "einvoicing/{$submission->id}/".now()->format('YmdHisv').'.txt';
                Storage::disk('local')->put($path, $result->document);
                $submission->payload_path = $path;
            }

            if ($submission->submitted_at === null && ! in_array($result->status, [SubmissionStatus::Pending, SubmissionStatus::Failed], true)) {
                $submission->submitted_at = now();
            }

            if ($result->status->isFinal()) {
                $submission->completed_at = now();
            }

            $submission->save();

            $invoice = $submission->invoice()->with('company.country')->firstOrFail();

            if ($result->status->isFinal() && CountryComplianceResolver::forCompany($invoice->company)->allowsRevisionAfter($result->status)) {
                $invoice->forceFill(['status' => InvoiceStatus::Draft, 'issued_at' => null])->save();
            }
        });

        return $submission;
    }
}
```

- [ ] **Step 4: Job** (`php artisan make:job SubmitInvoice --no-interaction`)

```php
<?php

namespace App\Jobs;

use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\EInvoicingProviderFactory;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\EInvoicing\SubmissionResultRecorder;
use App\Models\InvoiceSubmission;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

class SubmitInvoice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public InvoiceSubmission $submission) {}

    public function uniqueId(): string
    {
        return $this->submission->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(EInvoicingProviderFactory $factory, SubmissionResultRecorder $recorder): void
    {
        $submission = $this->submission->fresh(['integration', 'invoice.company.country', 'invoice.customer.country', 'invoice.rows']);

        if ($submission === null || $submission->status !== SubmissionStatus::Pending) {
            return;
        }

        try {
            $result = $factory->forIntegration($submission->integration)->send($submission->invoice);
        } catch (TransientProviderException $exception) {
            if ($this->job === null || $this->job instanceof SyncJob || $this->attempts() >= $this->tries) {
                $recorder->record($submission, SubmissionResult::failed($exception->getMessage()));

                return;
            }

            $this->release($this->backoff()[$this->attempts() - 1] ?? 900);

            return;
        }

        $recorder->record($submission, $result);
    }

    public function failed(?Throwable $exception): void
    {
        $submission = $this->submission->fresh();

        if ($submission?->status === SubmissionStatus::Pending) {
            app(SubmissionResultRecorder::class)->record($submission, SubmissionResult::failed($exception?->getMessage() ?? 'Submission failed.'));
        }
    }
}
```

With `withFakeQueueInteractions()`, `attempts()` returns 1, so the first retry uses `backoff()[0] = 60`.

- [ ] **Step 5: `IssueInvoice` creates the submission**

Replace `ensureSubmissionIsPossible()`:

```php
protected function ensureSubmissionIsPossible(Company $company, CountryComplianceRules $rules): ?EInvoicingIntegration
{
    if (! $rules->requiresSubmission()) {
        return null;
    }

    $integration = $company->eInvoicingIntegration;

    if ($integration === null || ! $integration->is_active || ! $integration->supportsCountry($company->country?->iso_code)) {
        throw ValidationException::withMessages([
            'einvoicing' => __('Configure electronic invoicing for this company before issuing invoices.'),
        ]);
    }

    return $integration;
}
```

and the end of `handle()`:

```php
$integration = $this->ensureSubmissionIsPossible($invoice->company, $rules);

$submission = DB::transaction(function () use ($invoice, $integration): ?InvoiceSubmission {
    Company::query()->whereKey($invoice->company_id)->lockForUpdate()->first();

    $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

    if ($locked->status !== InvoiceStatus::Draft) {
        throw ValidationException::withMessages(['invoice' => __('Only draft invoices can be issued.')]);
    }

    if ($invoice->submissions()->whereIn('status', [SubmissionStatus::Pending, SubmissionStatus::Submitted])->exists()) {
        throw ValidationException::withMessages(['invoice' => __('This invoice is already being submitted.')]);
    }

    $invoice->number ??= Invoice::nextNumber($invoice->company_id);
    $invoice->status = InvoiceStatus::Issued;
    $invoice->issued_at = now();
    $invoice->save();

    if ($integration === null) {
        return null;
    }

    return $invoice->submissions()->create([
        'e_invoicing_integration_id' => $integration->id,
        'driver' => $integration->driver,
        'status' => SubmissionStatus::Pending,
    ]);
});

if ($submission !== null) {
    SubmitInvoice::dispatch($submission);
}

return $invoice->refresh();
```

- [ ] **Step 6: Controller toast**

In `InvoiceSubmissionController::issue()`:

```php
$invoice = $issueInvoice->handle($invoice);
$submission = $invoice->latestSubmission;

Inertia::flash('toast', match ($submission?->status) {
    SubmissionStatus::Failed => ['type' => 'error', 'message' => __('The invoice could not be submitted: :error', ['error' => $submission->error_message])],
    SubmissionStatus::Pending => ['type' => 'success', 'message' => __('Invoice issued and queued for submission.')],
    null => ['type' => 'success', 'message' => __('Invoice issued.')],
    default => ['type' => 'success', 'message' => __('Invoice issued and submitted.')],
});

return to_route('invoices.edit', $invoice);
```

Add the new strings to `it.json`/`es.json`.

- [ ] **Step 7: Run tests**

Run: `php artisan test --compact tests/Feature/EInvoicing tests/Feature/IssueInvoiceTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: submit issued invoices to the e-invoicing provider"
```

---

### Task 10: Webhook endpoint

**Files:**
- Create: `routes/webhooks.php`, `app/Http/Controllers/EInvoicingWebhookController.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/EInvoicing/WebhookTest.php`

**Interfaces:**
- Consumes: `EInvoicingProviderFactory`, `EInvoicingProvider::parseWebhook()/fetchStatus()`, `SubmissionResultRecorder::record()`, `InvalidWebhookSignature`.
- Produces: `POST /webhooks/einvoicing/{driver}/{integration}` named `einvoicing.webhook`, in the `api` middleware group (no session, no CSRF). Responses: 200 (handled, ignored or duplicate), 403 (bad signature), 404 (unknown integration or driver mismatch).

- [ ] **Step 1: Failing tests** `tests/Feature/EInvoicing/WebhookTest.php`

```php
<?php

use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Support\Str;

beforeEach(function () {
    $company = Company::factory()->create(['country_id' => Country::factory()->spain()]);
    $this->integration = EInvoicingIntegration::factory()->create(['company_id' => $company->id]);
    $this->submission = InvoiceSubmission::factory()
        ->for(Invoice::factory()->issued()->create(['company_id' => $company->id]))
        ->create(['e_invoicing_integration_id' => $this->integration->id, 'external_id' => 'ext-1']);
});

function postWebhook(EInvoicingIntegration $integration, array $body, ?string $secret = null, string $driver = 'fake')
{
    return test()->postJson(
        route('einvoicing.webhook', ['driver' => $driver, 'integration' => $integration->id]),
        $body,
        ['X-Fake-Secret' => $secret ?? $integration->webhook_secret],
    );
}

test('a valid webhook updates the submission and stores the event', function () {
    postWebhook($this->integration, ['event_id' => 'evt-1', 'external_id' => 'ext-1', 'status' => 'accepted'])->assertOk();

    expect($this->submission->fresh()->status)->toBe(SubmissionStatus::Accepted);
    expect($this->submission->events()->count())->toBe(1);
});

test('an invalid signature is refused', function () {
    postWebhook($this->integration, ['event_id' => 'evt-1', 'external_id' => 'ext-1', 'status' => 'accepted'], 'wrong')->assertForbidden();

    expect($this->submission->fresh()->status)->toBe(SubmissionStatus::Submitted);
});

test('duplicate events are ignored', function () {
    postWebhook($this->integration, ['event_id' => 'evt-1', 'external_id' => 'ext-1', 'status' => 'rejected'])->assertOk();
    $this->submission->update(['status' => SubmissionStatus::Submitted]);

    postWebhook($this->integration, ['event_id' => 'evt-1', 'external_id' => 'ext-1', 'status' => 'rejected'])->assertOk();

    expect($this->submission->fresh()->status)->toBe(SubmissionStatus::Submitted);
    expect($this->submission->events()->count())->toBe(1);
});

test('unknown integrations and driver mismatches return 404', function () {
    test()->postJson(route('einvoicing.webhook', ['driver' => 'fake', 'integration' => (string) Str::uuid()]), [])->assertNotFound();
    postWebhook($this->integration, [], null, 'b2brouter')->assertNotFound();
});

test('irrelevant notifications are acknowledged', function () {
    postWebhook($this->integration, ['ping' => true])->assertOk();
});

test('a submission of another integration cannot be updated', function () {
    $otherIntegration = EInvoicingIntegration::factory()->create();

    postWebhook($otherIntegration, ['event_id' => 'evt-9', 'external_id' => 'ext-1', 'status' => 'rejected'])->assertOk();

    expect($this->submission->fresh()->status)->toBe(SubmissionStatus::Submitted);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/EInvoicing/WebhookTest.php`
Expected: FAIL (route not defined).

- [ ] **Step 3: Route registration**

`routes/webhooks.php`:

```php
<?php

use App\Http\Controllers\EInvoicingWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/einvoicing/{driver}/{integration}', EInvoicingWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('einvoicing.webhook');
```

`bootstrap/app.php`, inside `withRouting(...)`:

```php
then: function (): void {
    Route::middleware('api')->group(base_path('routes/webhooks.php'));
},
```

(import `Illuminate\Support\Facades\Route`).

- [ ] **Step 4: Controller**

```php
<?php

namespace App\Http\Controllers;

use App\EInvoicing\EInvoicingProviderFactory;
use App\EInvoicing\Exceptions\InvalidWebhookSignature;
use App\EInvoicing\SubmissionResultRecorder;
use App\Models\EInvoicingIntegration;
use App\Models\InvoiceSubmission;
use App\Models\InvoiceSubmissionEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class EInvoicingWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $driver,
        EInvoicingIntegration $integration,
        EInvoicingProviderFactory $factory,
        SubmissionResultRecorder $recorder,
    ): Response {
        abort_unless($integration->driver->value === $driver, 404);

        $provider = $factory->forIntegration($integration);

        try {
            $notification = $provider->parseWebhook($request, $integration);
        } catch (InvalidWebhookSignature) {
            abort(403);
        }

        if ($notification === null) {
            Log::info('Ignored e-invoicing webhook.', ['integration' => $integration->id]);

            return response('', 200);
        }

        $submission = InvoiceSubmission::query()
            ->where('e_invoicing_integration_id', $integration->id)
            ->where('external_id', $notification->externalId)
            ->latest()
            ->first();

        if ($submission === null) {
            Log::info('E-invoicing webhook for an unknown submission.', ['integration' => $integration->id, 'external_id' => $notification->externalId]);

            return response('', 200);
        }

        if (InvoiceSubmissionEvent::query()->where('provider_event_id', $notification->eventId)->exists()) {
            return response('', 200);
        }

        $submission->events()->create([
            'type' => $notification->type,
            'provider_event_id' => $notification->eventId,
            'payload' => $notification->payload,
            'received_at' => now(),
        ]);

        $recorder->record($submission, $notification->result ?? $provider->fetchStatus($submission));

        return response('', 200);
    }
}
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact tests/Feature/EInvoicing/WebhookTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: e-invoicing webhook endpoint"
```

---

### Task 11: Polling command, manual refresh, desktop auto-refresh

**Files:**
- Create: `app/EInvoicing/SubmissionStatusRefresher.php`, `app/Console/Commands/RefreshEInvoicingStatuses.php`
- Modify: `routes/console.php`, `routes/invoices.php`, `app/Http/Controllers/InvoiceSubmissionController.php`, `app/Http/Controllers/InvoiceController.php` (`edit`)
- Test: `tests/Feature/EInvoicing/RefreshStatusesTest.php`

**Interfaces:**
- Produces: `SubmissionStatusRefresher::refresh(InvoiceSubmission $submission): InvoiceSubmission` (no-op when not awaiting the authority or without `external_id`; lets `TransientProviderException` bubble). Command `einvoicing:refresh-statuses` (submissions `Submitted` with `submitted_at <= now()->subHour()`), scheduled every 30 minutes. Route `POST invoices/{invoice}/refresh-status` → `InvoiceSubmissionController@refresh`, named `invoices.refresh-status`. On the desktop build, `InvoiceController::edit` refreshes a `Submitted` latest submission at most every 5 minutes (`Cache::add("einvoicing:refresh:{id}", true, 300)`).

- [ ] **Step 1: Failing tests** `tests/Feature/EInvoicing/RefreshStatusesTest.php`

```php
<?php

use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\EInvoicing\Providers\FakeProvider;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use App\Models\User;

test('the command refreshes only submissions waiting for more than an hour', function () {
    $old = InvoiceSubmission::factory()->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()->subHours(2)]);
    $recent = InvoiceSubmission::factory()->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()->subMinutes(10)]);
    $final = InvoiceSubmission::factory()->create(['status' => SubmissionStatus::Accepted, 'submitted_at' => now()->subHours(2)]);
    FakeProvider::queueStatusResult(new SubmissionResult(SubmissionStatus::Accepted));

    $this->artisan('einvoicing:refresh-statuses')->assertSuccessful();

    expect($old->fresh()->status)->toBe(SubmissionStatus::Accepted);
    expect($recent->fresh()->status)->toBe(SubmissionStatus::Submitted);
    expect($final->fresh()->status)->toBe(SubmissionStatus::Accepted);
});

test('one failing submission does not stop the command', function () {
    InvoiceSubmission::factory()->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()->subHours(3)]);
    $second = InvoiceSubmission::factory()->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()->subHours(2)]);
    FakeProvider::queueStatusResult(new TransientProviderException('503'));
    FakeProvider::queueStatusResult(new SubmissionResult(SubmissionStatus::Accepted));

    $this->artisan('einvoicing:refresh-statuses')->assertSuccessful();

    expect($second->fresh()->status)->toBe(SubmissionStatus::Accepted);
});

test('the refresh route updates the latest submission', function () {
    $company = Company::factory()->create(['is_default' => true]);
    $invoice = Invoice::factory()->issued()->create(['company_id' => $company->id]);
    $submission = InvoiceSubmission::factory()->for($invoice)->create(['status' => SubmissionStatus::Submitted]);
    FakeProvider::queueStatusResult(new SubmissionResult(SubmissionStatus::Delivered));

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.refresh-status', $invoice))
        ->assertRedirect(route('invoices.edit', $invoice));

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Delivered);
});

test('the desktop build refreshes on open at most every five minutes', function () {
    config(['nativephp-internal.running' => true]);
    $company = Company::factory()->create(['is_default' => true]);
    $invoice = Invoice::factory()->issued()->create(['company_id' => $company->id]);
    $submission = InvoiceSubmission::factory()->for($invoice)->create(['status' => SubmissionStatus::Submitted]);
    FakeProvider::queueStatusResult(new SubmissionResult(SubmissionStatus::Submitted, 'processing'));
    FakeProvider::queueStatusResult(new SubmissionResult(SubmissionStatus::Accepted));
    $this->actingAs(User::factory()->create());

    $this->get(route('invoices.edit', $invoice))->assertOk();
    $this->get(route('invoices.edit', $invoice))->assertOk();

    expect($submission->fresh()->provider_status)->toBe('processing');
    expect($submission->fresh()->status)->toBe(SubmissionStatus::Submitted);
});
```

If `config(['nativephp-internal.running' => true])` triggers NativePHP middleware that blocks the test request (`PreventRegularBrowserAccess`), wrap the check in a small helper `App\Support\Desktop::isRunning(): bool` (returns `(bool) config('nativephp-internal.running')`) and in the test swap it instead: make it a static flag override (`Desktop::fake(true)`), mirroring `CurrentCompany::runningAs()`. Use the helper everywhere the plan says `config('nativephp-internal.running')`.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/EInvoicing/RefreshStatusesTest.php`
Expected: FAIL.

- [ ] **Step 3: Refresher**

```php
<?php

namespace App\EInvoicing;

use App\Models\InvoiceSubmission;

class SubmissionStatusRefresher
{
    public function __construct(
        private EInvoicingProviderFactory $factory,
        private SubmissionResultRecorder $recorder,
    ) {}

    public function refresh(InvoiceSubmission $submission): InvoiceSubmission
    {
        if (! $submission->status->isAwaitingAuthority() || $submission->external_id === null) {
            return $submission;
        }

        $result = $this->factory->forIntegration($submission->integration)->fetchStatus($submission);

        return $this->recorder->record($submission, $result);
    }
}
```

- [ ] **Step 4: Command** (`php artisan make:command RefreshEInvoicingStatuses --no-interaction`; keep the signature style the generator produces)

```php
<?php

namespace App\Console\Commands;

use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\SubmissionStatusRefresher;
use App\Models\InvoiceSubmission;
use Illuminate\Console\Command;
use Throwable;

class RefreshEInvoicingStatuses extends Command
{
    protected $signature = 'einvoicing:refresh-statuses';

    protected $description = 'Fetch the status of e-invoicing submissions still waiting for the tax authority';

    public function handle(SubmissionStatusRefresher $refresher): int
    {
        InvoiceSubmission::query()
            ->with('integration')
            ->where('status', SubmissionStatus::Submitted)
            ->where('submitted_at', '<=', now()->subHour())
            ->orderBy('submitted_at')
            ->each(function (InvoiceSubmission $submission) use ($refresher): void {
                try {
                    $refresher->refresh($submission);
                } catch (Throwable $exception) {
                    report($exception);
                    $this->warn("Submission {$submission->id}: {$exception->getMessage()}");
                }
            });

        return self::SUCCESS;
    }
}
```

`routes/console.php`:

```php
<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('einvoicing:refresh-statuses')->everyThirtyMinutes()->withoutOverlapping();
```

- [ ] **Step 5: Manual refresh and desktop auto-refresh**

`InvoiceSubmissionController`:

```php
public function refresh(Invoice $invoice, SubmissionStatusRefresher $refresher): RedirectResponse
{
    $this->authorizeCurrentCompany($invoice);

    $submission = $invoice->latestSubmission;

    if ($submission !== null) {
        try {
            $refresher->refresh($submission);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Submission status updated.')]);
        } catch (TransientProviderException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The provider is not reachable, try again later.')]);
        }
    }

    return to_route('invoices.edit', $invoice);
}
```

`routes/invoices.php`: `Route::post('invoices/{invoice}/refresh-status', [InvoiceSubmissionController::class, 'refresh'])->name('invoices.refresh-status');`

`InvoiceController::edit`, before rendering:

```php
$latestSubmission = $invoice->latestSubmission;

if (config('nativephp-internal.running')
    && $latestSubmission?->status === SubmissionStatus::Submitted
    && Cache::add("einvoicing:refresh:{$latestSubmission->id}", true, 300)) {
    rescue(fn () => app(SubmissionStatusRefresher::class)->refresh($latestSubmission), report: true);
}
```

Add the new strings to `it.json`/`es.json`.

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact tests/Feature/EInvoicing/RefreshStatusesTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
php artisan wayfinder:generate
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: refresh e-invoicing submission statuses"
```

---

### Task 12: `B2BrouterProvider` and `InvoicePayloadMapper`

**Reference** (from the B2Brouter OpenAPI spec `2026-06-26` and the `b2brouter/b2brouter-php` SDK; items marked UNVERIFIED must be checked against staging before production use — Step 10):

- Base URL: `https://api.b2brouter.net` for `Sandbox` (the `test_` API key routes to the sandbox) and `Production`; `https://api-staging.b2brouter.net` for `Staging`. No `/v1` prefix, no `.json` suffix.
- Headers: `X-B2B-API-Key: {api_key}`, `X-B2B-API-Version: 2026-06-26`, `Accept: application/json`, JSON body.
- `GET /accounts/{account}` → credentials check (401 bad key, 403 no permission).
- `POST /accounts/{account}/invoices` with `{"send_after_import": true, "invoice": {...}}` → 201 `{"invoice": {"id", "state", "tax_report_ids", ...}}`.
- `GET /invoices/{id}` → `{"invoice": {"id", "state", "to_net_id" (IdentificativoSdI), "error_message", "refuse_reason", "tax_report_ids": [...]}}`.
- `GET /tax_reports/{id}` → `{"tax_report": {"id", "type", "state", "qr" (base64 PNG), "identifier" (AEAT verification URL), "errors": [{code, description}], "has_errors"}}` (wrapper UNVERIFIED). There is no CSV for VERI*FACTU: `authority_id` stores `identifier` for Spain and `to_net_id` (IdentificativoSdI) for Italy.
- Errors: `{"error": {"code", "message", "param"}}`; some 404s are `{"error": "Not found"}`; 429 has no code.
- Invoice body: `type: "IssuedInvoice"`, `number`, `date`, `currency`, `language`, `extra_info`, inline `contact {name, tin_value, tin_scheme, cin_value, cin_scheme, address, postalcode, city, province, country (lowercase ISO), email}`; Italy contact extras `transport_type_code: "it.sdi"`, `document_type_code: "xml.fatturapa.1.2"`, `recipient_code`, `certified_email` (acceptance on an inline contact UNVERIFIED). Schemes: IT partita IVA `9906`, IT codice fiscale `9907` (cin), ES NIF `9920`.
- Lines `invoice_lines_attributes[]`: `description`, `quantity`, `price` (net), `taxes_attributes[]` with `name: "IVA"`, `category`, `percent`, `comment`. IT 0%: `category` = Natura code (e.g. `N2.1`), `percent: 0`. ES 0%: `category: "E"` + `comment: "E1".."E6"` (exempt) or `category: "NS"` + `comment: "N1"|"N2"` (not subject). Standard rate: `category: "S"`.
- Italy's tax regime (RF..) is an account-level setting in B2Brouter, not an invoice field.
- Credit notes: Italy `is_credit_note: true` with **positive** amounts (TD04 automatic). Spain: **no** `is_credit_note`, a standard invoice with negative amounts (rectificativa through the account's `credit_note_code` default); `invoice_references` omitted (UNVERIFIED whether AEAT accepts it without the reference).
- Webhooks: header `X-B2Brouter-Signature: t={timestamp},s={hex}`, `s = hash_hmac('sha256', "{t}.{raw body}", signing_secret)`; the signing secret is returned only when the webhook is created in B2Brouter, so the user pastes it into the `webhook_signing_secret` credential. Without it, the webhook URL carries `?secret={integration.webhook_secret}` (Task 13) and is checked instead. Events (`code`): `issued_invoice.state_change` (`data.invoice_id`, `data.event_id`, `data.state`) and `tax_report.state_change` (`data.event_id`, `data.object.invoice_id`, `data.state`). Notifications return `result = null`, so the webhook controller fetches the full status (including the QR).
- Status mapping (tax report state wins over invoice state; SDI tax report states UNVERIFIED):

| Source state | `SubmissionStatus` |
|---|---|
| invoice `error`, `invalid`, no tax report | `Failed` |
| tax report `new`, `processing`, `signed`, `sending`, `sent`, `clearing`; invoice `new`, `sending`, `sent`, `issued` | `Submitted` |
| tax report `registered`, `acknowledged` — IT | `Delivered` |
| tax report `registered`, `registered_with_errors`, `acknowledged` — ES | `Accepted` |
| tax report `deposited` — IT | `NotDelivered` |
| tax report `refused`, `error`, `invalid`, `annulled` | `Rejected` |

**Files:**
- Modify: `app/EInvoicing/Providers/B2BrouterProvider.php`
- Create: `app/EInvoicing/Providers/B2Brouter/InvoicePayloadMapper.php`, `app/EInvoicing/Providers/B2Brouter/StatusMapper.php`
- Create: `tests/Fixtures/b2brouter/{invoice-created-it,invoice-created-es,tax-report-registered-es,tax-report-deposited-it,error-422,error-401}.json`
- Test: `tests/Feature/EInvoicing/B2BrouterPayloadMapperTest.php`, `tests/Feature/EInvoicing/B2BrouterProviderTest.php`

**Interfaces:**
- Consumes: contract, DTOs, exceptions, models.
- Produces: `InvoicePayloadMapper::map(Invoice $invoice): array<string, mixed>` (the `invoice` object, without wrapper); `StatusMapper::map(?string $invoiceState, ?string $taxReportState, ?string $isoCode): SubmissionStatus`; `B2BrouterProvider::API_VERSION = '2026-06-26'`.

- [ ] **Step 1: Fixtures**

`invoice-created-es.json`:

```json
{"invoice": {"id": 4711, "number": "2026-0001", "state": "sending", "tax_report_ids": [91], "to_net": "es.verifactu", "to_net_id": null, "error_message": null}}
```

`tax-report-registered-es.json`:

```json
{"tax_report": {"id": 91, "type": "Verifactu", "invoice_id": 4711, "state": "registered", "qr": "iVBORw0KGgoAAAANSUhEUg==", "identifier": "https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=B12345678&numserie=2026-0001", "has_errors": false, "errors": []}}
```

`invoice-created-it.json`:

```json
{"invoice": {"id": 5001, "number": "2026-0001", "state": "sending", "tax_report_ids": [], "to_net": "it.sdi", "to_net_id": null}}
```

`tax-report-deposited-it.json`:

```json
{"tax_report": {"id": 92, "type": "Sdi", "invoice_id": 5001, "state": "deposited", "has_errors": false, "errors": []}}
```

`error-422.json`:

```json
{"error": {"code": "parameter_taken", "message": "Number has already been taken", "param": "number", "type": "invalid_request_error"}}
```

`error-401.json`:

```json
{"error": {"code": "invalid_api_key", "message": "Invalid API key"}}
```

- [ ] **Step 2: Failing mapper tests** `tests/Feature/EInvoicing/B2BrouterPayloadMapperTest.php`

```php
<?php

use App\EInvoicing\Providers\B2Brouter\InvoicePayloadMapper;
use App\Enums\InvoiceType;
use App\Models\Company;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;

function mappedInvoice(string $companyIso, array $customer, array $rows, bool $creditNote = false): array
{
    $company = Company::factory()->create(['country_id' => Country::factory()->create(['iso_code' => $companyIso]), 'vat_number' => 'X1']);
    $customerCountry = Country::query()->where('iso_code', $customer['iso'])->first() ?? Country::factory()->create(['iso_code' => $customer['iso']]);
    unset($customer['iso']);
    $invoice = Invoice::factory()->issued()->create([
        'company_id' => $company->id,
        'customer_id' => Customer::factory()->create(['company_id' => $company->id, 'country_id' => $customerCountry->id, 'zip' => null, 'fiscal_details' => null, ...$customer])->id,
        'number' => '2026-0001',
        'invoice_date' => '2026-10-02',
        'language' => 'it',
        'type' => $creditNote ? InvoiceType::CreditNote : InvoiceType::Invoice,
    ]);

    foreach ($rows as $row) {
        InvoiceRow::factory()->for($invoice)->create(['vat_exemption_code' => null, ...$row]);
    }

    return (new InvoicePayloadMapper)->map($invoice->fresh(['company.country', 'customer.country', 'rows']));
}

test('italian b2b invoice', function () {
    $payload = mappedInvoice('IT', ['iso' => 'IT', 'vat_number' => '09876543210', 'zip' => '00100', 'fiscal_details' => ['recipient_code' => 'abc1234']], [
        ['description' => 'Consulting', 'quantity' => 2, 'price' => 100, 'vat_rate' => 22],
    ]);

    expect($payload)->toMatchArray(['type' => 'IssuedInvoice', 'number' => '2026-0001', 'date' => '2026-10-02', 'currency' => 'EUR']);
    expect($payload['contact'])->toMatchArray([
        'tin_value' => '09876543210', 'tin_scheme' => '9906', 'country' => 'it', 'postalcode' => '00100',
        'transport_type_code' => 'it.sdi', 'document_type_code' => 'xml.fatturapa.1.2', 'recipient_code' => 'ABC1234',
    ]);
    expect($payload['invoice_lines_attributes'][0])->toMatchArray([
        'description' => 'Consulting', 'quantity' => 2.0, 'price' => 100.0,
        'taxes_attributes' => [['name' => 'IVA', 'category' => 'S', 'percent' => 22.0]],
    ]);
});

test('italian private customer uses the tax code and 0000000', function () {
    $payload = mappedInvoice('IT', ['iso' => 'IT', 'vat_number' => null, 'tax_code' => 'RSSMRA80A01H501U'], [['vat_rate' => 22]]);

    expect($payload['contact'])->toMatchArray(['cin_value' => 'RSSMRA80A01H501U', 'cin_scheme' => '9907', 'recipient_code' => '0000000']);
    expect($payload['contact'])->not->toHaveKey('tin_value');
});

test('italian foreign customer uses XXXXXXX, 00000 and a natura code', function () {
    $payload = mappedInvoice('IT', ['iso' => 'DE', 'vat_number' => '123456789'], [['vat_rate' => 0, 'vat_exemption_code' => 'N3.2']]);

    expect($payload['contact'])->toMatchArray(['recipient_code' => 'XXXXXXX', 'postalcode' => '00000', 'tin_value' => 'DE123456789', 'country' => 'de']);
    expect($payload['contact'])->not->toHaveKey('tin_scheme');
    expect($payload['invoice_lines_attributes'][0]['taxes_attributes'][0])->toBe(['name' => 'IVA', 'category' => 'N3.2', 'percent' => 0.0]);
});

test('italian credit note uses positive amounts and the pec', function () {
    $payload = mappedInvoice('IT', ['iso' => 'IT', 'vat_number' => '09876543210', 'fiscal_details' => ['pec' => 'x@pec.it']], [['price' => -50, 'quantity' => 1, 'vat_rate' => 22]], creditNote: true);

    expect($payload['is_credit_note'])->toBeTrue();
    expect($payload['invoice_lines_attributes'][0]['price'])->toBe(50.0);
    expect($payload['contact']['certified_email'])->toBe('x@pec.it');
    expect($payload['contact']['recipient_code'])->toBe('0000000');
});

test('spanish invoice with exemption causes', function () {
    $payload = mappedInvoice('ES', ['iso' => 'ES', 'vat_number' => 'B87654321'], [
        ['vat_rate' => 0, 'vat_exemption_code' => 'E5'],
        ['vat_rate' => 0, 'vat_exemption_code' => 'N2'],
        ['vat_rate' => 21],
    ]);

    expect($payload['contact'])->toMatchArray(['tin_value' => 'ESB87654321', 'tin_scheme' => '9920', 'country' => 'es']);
    expect($payload['contact'])->not->toHaveKey('transport_type_code');
    expect(array_column($payload['invoice_lines_attributes'], 'taxes_attributes'))->toBe([
        [['name' => 'IVA', 'category' => 'E', 'percent' => 0.0, 'comment' => 'E5']],
        [['name' => 'IVA', 'category' => 'NS', 'percent' => 0.0, 'comment' => 'N2']],
        [['name' => 'IVA', 'category' => 'S', 'percent' => 21.0]],
    ]);
});

test('spanish credit note keeps negative amounts without the credit note flag', function () {
    $payload = mappedInvoice('ES', ['iso' => 'ES', 'vat_number' => 'B87654321'], [['price' => -50, 'quantity' => 1, 'vat_rate' => 21]], creditNote: true);

    expect($payload)->not->toHaveKey('is_credit_note');
    expect($payload['invoice_lines_attributes'][0]['price'])->toBe(-50.0);
});
```

- [ ] **Step 3: Failing provider tests** `tests/Feature/EInvoicing/B2BrouterProviderTest.php`

```php
<?php

use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Exceptions\InvalidWebhookSignature;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\EInvoicing\Providers\B2Brouter\StatusMapper;
use App\EInvoicing\Providers\B2BrouterProvider;
use App\Models\Company;
use App\Models\Country;
use App\Models\Customer;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\InvoiceSubmission;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

function b2brouterFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/b2brouter/{$name}.json")), true);
}

function b2brouter(EInvoicingEnvironment $environment = EInvoicingEnvironment::Sandbox, array $credentials = ['api_key' => 'test_key', 'account_id' => '42', 'webhook_signing_secret' => 'whsec']): B2BrouterProvider
{
    return new B2BrouterProvider($credentials, $environment);
}

function spanishIssuedInvoice(): Invoice
{
    $spain = Country::factory()->spain()->create();
    $company = Company::factory()->create(['country_id' => $spain->id, 'vat_number' => 'B12345678']);
    $invoice = Invoice::factory()->issued()->create([
        'company_id' => $company->id,
        'customer_id' => Customer::factory()->create(['company_id' => $company->id, 'country_id' => $spain->id, 'vat_number' => 'B87654321'])->id,
    ]);
    InvoiceRow::factory()->for($invoice)->create(['vat_rate' => 21]);

    return $invoice->fresh(['company.country', 'customer.country', 'rows']);
}

function b2brouterIntegration(string $webhookSecret = 'local-secret'): EInvoicingIntegration
{
    $integration = EInvoicingIntegration::factory()->make(['driver' => EInvoicingDriver::B2Brouter]);
    $integration->webhook_secret = $webhookSecret;

    return $integration;
}

test('send creates the invoice with send_after_import and reads the qr', function () {
    Http::fake([
        'api.b2brouter.net/accounts/42/invoices' => Http::response(b2brouterFixture('invoice-created-es'), 201),
        'api.b2brouter.net/tax_reports/91' => Http::response(b2brouterFixture('tax-report-registered-es')),
    ]);

    $result = b2brouter()->send(spanishIssuedInvoice());

    expect($result->status)->toBe(SubmissionStatus::Accepted);
    expect($result->externalId)->toBe('4711');
    expect($result->qrCode)->toBe('iVBORw0KGgoAAAANSUhEUg==');
    expect($result->authorityId)->toStartWith('https://www2.agenciatributaria.gob.es');
    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.b2brouter.net/accounts/42/invoices'
        && $request->hasHeader('X-B2B-API-Key', 'test_key')
        && $request->hasHeader('X-B2B-API-Version', B2BrouterProvider::API_VERSION)
        && $request['send_after_import'] === true
        && $request['invoice']['number'] !== null);
});

test('staging uses the staging host', function () {
    Http::fake(['api-staging.b2brouter.net/*' => Http::response(b2brouterFixture('invoice-created-it'), 201)]);

    expect(b2brouter(EInvoicingEnvironment::Staging)->send(spanishIssuedInvoice())->status)->toBe(SubmissionStatus::Submitted);
});

test('client errors fail definitively with the provider message', function (string $fixtureName, int $status) {
    Http::fake(['*' => Http::response(b2brouterFixture($fixtureName), $status)]);

    $result = b2brouter()->send(spanishIssuedInvoice());

    expect($result->status)->toBe(SubmissionStatus::Failed);
    expect($result->errorMessage)->toBe(b2brouterFixture($fixtureName)['error']['message']);
})->with([['error-422', 422], ['error-401', 401], ['error-401', 403]]);

test('server errors, rate limits and timeouts are transient', function (string $case) {
    Http::fake(['*' => match ($case) {
        'server error' => Http::response('', 503),
        'rate limit' => Http::response('', 429),
        'timeout' => Http::failedConnection(),
    }]);

    expect(fn () => b2brouter()->send(spanishIssuedInvoice()))->toThrow(TransientProviderException::class);
})->with(['server error', 'rate limit', 'timeout']);

test('fetch status maps the latest tax report', function () {
    Http::fake([
        'api.b2brouter.net/invoices/5001' => Http::response(['invoice' => ['id' => 5001, 'state' => 'sent', 'tax_report_ids' => [92], 'to_net_id' => 'SDI-778']]),
        'api.b2brouter.net/tax_reports/92' => Http::response(b2brouterFixture('tax-report-deposited-it')),
    ]);
    $company = Company::factory()->create(['country_id' => Country::factory()->italy()]);
    $submission = InvoiceSubmission::factory()
        ->for(Invoice::factory()->issued()->create(['company_id' => $company->id]))
        ->create(['external_id' => '5001', 'driver' => EInvoicingDriver::B2Brouter]);

    $result = b2brouter()->fetchStatus($submission);

    expect($result->status)->toBe(SubmissionStatus::NotDelivered);
    expect($result->providerStatus)->toBe('deposited');
    expect($result->authorityId)->toBe('SDI-778');
});

test('status mapping', function (?string $invoiceState, ?string $taxReportState, string $isoCode, SubmissionStatus $expected) {
    expect(StatusMapper::map($invoiceState, $taxReportState, $isoCode))->toBe($expected);
})->with([
    ['sending', null, 'ES', SubmissionStatus::Submitted],
    ['error', null, 'IT', SubmissionStatus::Failed],
    ['sent', 'processing', 'ES', SubmissionStatus::Submitted],
    ['sent', 'registered', 'ES', SubmissionStatus::Accepted],
    ['sent', 'registered_with_errors', 'ES', SubmissionStatus::Accepted],
    ['sent', 'registered', 'IT', SubmissionStatus::Delivered],
    ['sent', 'deposited', 'IT', SubmissionStatus::NotDelivered],
    ['sent', 'refused', 'ES', SubmissionStatus::Rejected],
    ['error', 'error', 'IT', SubmissionStatus::Rejected],
]);

test('webhook hmac signature is verified', function () {
    $body = json_encode(['code' => 'tax_report.state_change', 'triggered_at' => 1732530071, 'data' => ['event_id' => 'e-1', 'state' => 'registered', 'object' => ['invoice_id' => 4711]]]);
    $timestamp = '1732530076';
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec');

    $valid = Request::create('/', 'POST', server: ['HTTP_X_B2BROUTER_SIGNATURE' => "t={$timestamp},s={$signature}"], content: $body);
    $notification = b2brouter()->parseWebhook($valid, b2brouterIntegration());

    expect($notification->eventId)->toBe('e-1');
    expect($notification->externalId)->toBe('4711');
    expect($notification->type)->toBe('tax_report.state_change');
    expect($notification->result)->toBeNull();

    $invalid = Request::create('/', 'POST', server: ['HTTP_X_B2BROUTER_SIGNATURE' => "t={$timestamp},s=deadbeef"], content: $body);
    expect(fn () => b2brouter()->parseWebhook($invalid, b2brouterIntegration()))->toThrow(InvalidWebhookSignature::class);
});

test('without a signing secret the integration secret in the query string is checked', function () {
    $provider = b2brouter(credentials: ['api_key' => 'k', 'account_id' => '42']);
    $body = json_encode(['code' => 'issued_invoice.state_change', 'data' => ['event_id' => 'e-2', 'invoice_id' => 9, 'state' => 'sent']]);

    expect($provider->parseWebhook(Request::create('/?secret=local-secret', 'POST', content: $body), b2brouterIntegration())->externalId)->toBe('9');
    expect(fn () => $provider->parseWebhook(Request::create('/?secret=nope', 'POST', content: $body), b2brouterIntegration()))
        ->toThrow(InvalidWebhookSignature::class);
});

test('irrelevant webhook events return null', function () {
    $provider = b2brouter(credentials: ['api_key' => 'k', 'account_id' => '42']);
    $request = Request::create('/?secret=local-secret', 'POST', content: json_encode(['code' => 'received_invoice.created', 'data' => ['event_id' => 'e-3']]));

    expect($provider->parseWebhook($request, b2brouterIntegration()))->toBeNull();
});

test('test connection checks the account', function () {
    Http::fake(['api.b2brouter.net/accounts/42' => Http::sequence()->push(['account' => ['id' => 42]])->push(b2brouterFixture('error-401'), 401)]);

    expect(b2brouter()->testConnection())->toBeTrue();
    expect(b2brouter()->testConnection())->toBeFalse();
});
```

(Check `search-docs` for `Http::failedConnection()`; if it does not exist in this version, use `fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')` as the fake response.)

- [ ] **Step 4: Run to verify failure**

Run: `php artisan test --compact tests/Feature/EInvoicing/B2BrouterPayloadMapperTest.php tests/Feature/EInvoicing/B2BrouterProviderTest.php`
Expected: FAIL.

- [ ] **Step 5: `StatusMapper`**

```php
<?php

namespace App\EInvoicing\Providers\B2Brouter;

use App\EInvoicing\Enums\SubmissionStatus;

class StatusMapper
{
    public static function map(?string $invoiceState, ?string $taxReportState, ?string $isoCode): SubmissionStatus
    {
        if ($taxReportState !== null) {
            return match ($taxReportState) {
                'registered', 'acknowledged' => $isoCode === 'IT' ? SubmissionStatus::Delivered : SubmissionStatus::Accepted,
                'registered_with_errors' => SubmissionStatus::Accepted,
                'deposited' => SubmissionStatus::NotDelivered,
                'refused', 'error', 'invalid', 'annulled' => SubmissionStatus::Rejected,
                default => SubmissionStatus::Submitted,
            };
        }

        return in_array($invoiceState, ['error', 'invalid'], true)
            ? SubmissionStatus::Failed
            : SubmissionStatus::Submitted;
    }
}
```

- [ ] **Step 6: `InvoicePayloadMapper`**

```php
<?php

namespace App\EInvoicing\Providers\B2Brouter;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;

/**
 * Maps an Invoice (with company.country, customer.country and rows loaded) to the B2Brouter "invoice" object.
 */
class InvoicePayloadMapper
{
    /**
     * @return array<string, mixed>
     */
    public function map(Invoice $invoice): array
    {
        $companyIso = $invoice->company->country?->iso_code;
        $isItalianCreditNote = $invoice->isCreditNote() && $companyIso === 'IT';

        return array_filter([
            'type' => 'IssuedInvoice',
            'number' => $invoice->number,
            'date' => $invoice->invoice_date->format('Y-m-d'),
            'currency' => 'EUR',
            'language' => $invoice->language,
            'is_credit_note' => $isItalianCreditNote ? true : null,
            'extra_info' => $invoice->note,
            'contact' => $this->contact($invoice->customer, $companyIso),
            'invoice_lines_attributes' => $invoice->rows->map(fn (InvoiceRow $row): array => [
                'description' => $row->description,
                'quantity' => (float) $row->quantity,
                'price' => $isItalianCreditNote ? abs((float) $row->price) : (float) $row->price,
                'taxes_attributes' => [$this->tax($row, $companyIso)],
            ])->all(),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function contact(Customer $customer, ?string $companyIso): array
    {
        $customerIso = $customer->country?->iso_code;
        $isForeign = $customerIso !== $companyIso;
        $vatNumber = $customer->vat_number;

        $contact = [
            'name' => $customer->name,
            'address' => $customer->address,
            'postalcode' => $customer->zip ?? ($companyIso === 'IT' && $isForeign ? '00000' : null),
            'city' => $customer->city,
            'province' => $customer->state,
            'country' => $customerIso ? strtolower($customerIso) : null,
            'email' => $customer->email,
        ];

        if (filled($vatNumber)) {
            $hasCountryPrefix = $customerIso !== null && str_starts_with(strtoupper($vatNumber), $customerIso);

            $contact['tin_value'] = match (true) {
                $customerIso === 'IT', $customerIso === null, $hasCountryPrefix => $vatNumber,
                default => $customerIso.$vatNumber,
            };
            $contact['tin_scheme'] = match ($customerIso) {
                'IT' => '9906',
                'ES' => '9920',
                default => null,
            };
        }

        if ($companyIso === 'IT') {
            if (filled($customer->tax_code) && blank($vatNumber)) {
                $contact['cin_value'] = $customer->tax_code;
                $contact['cin_scheme'] = '9907';
            }

            $recipientCode = $customer->fiscal_details['recipient_code'] ?? null;

            $contact['transport_type_code'] = 'it.sdi';
            $contact['document_type_code'] = 'xml.fatturapa.1.2';
            $contact['recipient_code'] = match (true) {
                $isForeign => 'XXXXXXX',
                filled($recipientCode) => strtoupper($recipientCode),
                default => '0000000',
            };
            $contact['certified_email'] = $customer->fiscal_details['pec'] ?? null;
        }

        return array_filter($contact, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function tax(InvoiceRow $row, ?string $companyIso): array
    {
        $rate = (float) $row->vat_rate;

        if ($rate > 0 || $row->vat_exemption_code === null) {
            return ['name' => 'IVA', 'category' => 'S', 'percent' => $rate];
        }

        if ($companyIso === 'IT') {
            return ['name' => 'IVA', 'category' => $row->vat_exemption_code, 'percent' => 0.0];
        }

        return [
            'name' => 'IVA',
            'category' => str_starts_with($row->vat_exemption_code, 'E') ? 'E' : 'NS',
            'percent' => 0.0,
            'comment' => $row->vat_exemption_code,
        ];
    }
}
```

- [ ] **Step 7: `B2BrouterProvider`** (replace the placeholder)

```php
<?php

namespace App\EInvoicing\Providers;

use App\EInvoicing\Contracts\EInvoicingProvider;
use App\EInvoicing\Data\ProviderNotification;
use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\Capability;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use App\EInvoicing\Exceptions\InvalidWebhookSignature;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\EInvoicing\Providers\B2Brouter\InvoicePayloadMapper;
use App\EInvoicing\Providers\B2Brouter\StatusMapper;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class B2BrouterProvider implements EInvoicingProvider
{
    public const API_VERSION = '2026-06-26';

    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(private array $credentials, private EInvoicingEnvironment $environment) {}

    public function send(Invoice $invoice): SubmissionResult
    {
        $response = $this->call(fn (PendingRequest $http): Response => $http->post("/accounts/{$this->accountId()}/invoices", [
            'send_after_import' => true,
            'invoice' => (new InvoicePayloadMapper)->map($invoice),
        ]));

        if ($response->failed()) {
            return SubmissionResult::failed($this->errorMessage($response), $response->body());
        }

        return $this->resultFromInvoice($response->json('invoice') ?? [], $invoice->company->country?->iso_code, $response->body());
    }

    public function fetchStatus(InvoiceSubmission $submission): SubmissionResult
    {
        $response = $this->call(fn (PendingRequest $http): Response => $http->get("/invoices/{$submission->external_id}"));

        if ($response->failed()) {
            return new SubmissionResult($submission->status, $submission->provider_status, errorMessage: $this->errorMessage($response));
        }

        return $this->resultFromInvoice($response->json('invoice') ?? [], $submission->invoice->company->country?->iso_code, $response->body());
    }

    public function parseWebhook(Request $request, EInvoicingIntegration $integration): ?ProviderNotification
    {
        $this->verifyWebhook($request, $integration);

        $payload = json_decode($request->getContent(), true) ?? [];
        $code = $payload['code'] ?? null;
        $data = $payload['data'] ?? [];

        $invoiceId = match ($code) {
            'issued_invoice.state_change' => $data['invoice_id'] ?? null,
            'tax_report.state_change' => $data['object']['invoice_id'] ?? null,
            default => null,
        };

        if ($invoiceId === null || ! isset($data['event_id'])) {
            return null;
        }

        return new ProviderNotification((string) $data['event_id'], (string) $invoiceId, (string) $code, $payload);
    }

    public function testConnection(): bool
    {
        try {
            return $this->http()->get("/accounts/{$this->accountId()}")->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function capabilities(): array
    {
        return [Capability::ItalySdi, Capability::SpainVerifactu];
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function resultFromInvoice(array $invoice, ?string $isoCode, string $document): SubmissionResult
    {
        $taxReport = null;
        $taxReportId = collect($invoice['tax_report_ids'] ?? [])->last();

        if ($taxReportId !== null) {
            $taxReportResponse = $this->call(fn (PendingRequest $http): Response => $http->get("/tax_reports/{$taxReportId}"));
            $taxReport = $taxReportResponse->successful() ? $taxReportResponse->json('tax_report') : null;
        }

        $taxReportErrors = collect($taxReport['errors'] ?? [])
            ->map(fn (array $error): string => trim(($error['code'] ?? '').' '.($error['description'] ?? '')))
            ->implode('; ');

        return new SubmissionResult(
            status: StatusMapper::map($invoice['state'] ?? null, $taxReport['state'] ?? null, $isoCode),
            providerStatus: $taxReport['state'] ?? $invoice['state'] ?? null,
            externalId: isset($invoice['id']) ? (string) $invoice['id'] : null,
            authorityId: $isoCode === 'IT' ? ($invoice['to_net_id'] ?? null) : ($taxReport['identifier'] ?? null),
            qrCode: $taxReport['qr'] ?? null,
            errorMessage: $taxReportErrors !== '' ? $taxReportErrors : ($invoice['error_message'] ?? $invoice['refuse_reason'] ?? null),
            document: $document,
        );
    }

    private function verifyWebhook(Request $request, EInvoicingIntegration $integration): void
    {
        $signingSecret = $this->credentials['webhook_signing_secret'] ?? null;

        if (blank($signingSecret)) {
            if (! hash_equals($integration->webhook_secret, (string) $request->query('secret'))) {
                throw new InvalidWebhookSignature('Invalid webhook secret.');
            }

            return;
        }

        parse_str(str_replace(',', '&', (string) $request->header('X-B2Brouter-Signature')), $parts);
        $expected = hash_hmac('sha256', ($parts['t'] ?? '').'.'.$request->getContent(), $signingSecret);

        if (! isset($parts['s']) || ! hash_equals($expected, (string) $parts['s'])) {
            throw new InvalidWebhookSignature('Invalid B2Brouter signature.');
        }
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    private function call(callable $request): Response
    {
        try {
            $response = $request($this->http());
        } catch (ConnectionException $exception) {
            throw new TransientProviderException($exception->getMessage(), previous: $exception);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new TransientProviderException("B2Brouter responded with HTTP {$response->status()}.");
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        $baseUrl = $this->environment === EInvoicingEnvironment::Staging
            ? 'https://api-staging.b2brouter.net'
            : 'https://api.b2brouter.net';

        return Http::baseUrl($baseUrl)
            ->withHeaders([
                'X-B2B-API-Key' => $this->credentials['api_key'] ?? '',
                'X-B2B-API-Version' => self::API_VERSION,
            ])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(30);
    }

    private function accountId(): string
    {
        return $this->credentials['account_id'] ?? '';
    }

    private function errorMessage(Response $response): string
    {
        $error = $response->json('error');

        return match (true) {
            is_array($error) => (string) ($error['message'] ?? $error['code'] ?? 'Unknown error'),
            is_string($error) => $error,
            default => (string) ($response->json('message') ?? "HTTP {$response->status()}"),
        };
    }
}
```

- [ ] **Step 8: Run tests**

Run: `php artisan test --compact tests/Feature/EInvoicing`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: B2Brouter e-invoicing driver"
```

- [ ] **Step 10: Staging verification (manual; needs B2Brouter staging credentials from the user — does not block merging the branch, blocks production use)**

With a staging account, issue through the UI (environment `Staging`): one Italian B2B, one Italian private, one Italian foreign, one Spanish domestic, one Spanish foreign invoice and one credit note per country. Save the real responses over the fixtures and confirm: (a) inline contacts accept `recipient_code`/`certified_email`; (b) the SDI tax report states used in `StatusMapper`; (c) the `tax_report` wrapper; (d) Spanish rectificativas without `invoice_references`; (e) how B2Brouter expects the identifier type of foreign Spanish counterparts (`fiscal_details.id_type`, not mapped yet). Adjust the mapper/status mapper and their tests to the recorded data and report the findings to the user.

---

## Phase 4 — UI, PDF, MCP

### Task 13: Company e-invoicing settings page

**Files:**
- Create: `app/Http/Controllers/EInvoicingIntegrationController.php`, `app/Http/Requests/UpdateEInvoicingIntegrationRequest.php`
- Create: `resources/js/pages/companies/EInvoicing.vue`
- Modify: `routes/companies.php`, `resources/js/pages/companies/Edit.vue`, lang files
- Test: `tests/Feature/EInvoicing/EInvoicingSettingsTest.php`

**Interfaces:**
- Consumes: `EInvoicingDriver::availableFor()/credentialRules()/credentialFields()/label()`, `EInvoicingEnvironment`, `EInvoicingProviderFactory`.
- Produces: routes `companies.e-invoicing.edit` (GET `companies/{company}/e-invoicing`), `companies.e-invoicing.update` (PUT), `companies.e-invoicing.test` (POST `companies/{company}/e-invoicing/test`). Inertia props: `company {id, name, country_iso}`, `drivers: list<{value, label, fields}>`, `environments: list<string>`, `integration: {driver, environment, is_active, configured_credentials: list<string>} | null`, `webhookUrl: string | null` (null on desktop or without an integration).

- [ ] **Step 1: Failing tests** `tests/Feature/EInvoicing/EInvoicingSettingsTest.php`

```php
<?php

use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Providers\FakeProvider;
use App\Models\Company;
use App\Models\Country;
use App\Models\EInvoicingIntegration;
use App\Models\User;

beforeEach(function () {
    $this->company = Company::factory()->create(['country_id' => Country::factory()->spain()]);
    $this->actingAs(User::factory()->create());
});

test('the settings page lists drivers for the company country and never exposes credentials', function () {
    EInvoicingIntegration::factory()->create([
        'company_id' => $this->company->id,
        'driver' => EInvoicingDriver::B2Brouter,
        'credentials' => ['api_key' => 'super-secret', 'account_id' => '42'],
    ]);

    $response = $this->get(route('companies.e-invoicing.edit', $this->company));

    $response->assertInertia(fn ($page) => $page
        ->component('companies/EInvoicing')
        ->where('integration.configured_credentials', ['api_key', 'account_id'])
        ->where('drivers.0.value', 'b2brouter')
        ->whereNot('webhookUrl', null));
    expect($response->getContent())->not->toContain('super-secret');
});

test('credentials are saved encrypted', function () {
    $this->put(route('companies.e-invoicing.update', $this->company), [
        'driver' => 'b2brouter', 'environment' => 'sandbox', 'is_active' => true,
        'credentials' => ['api_key' => 'test_abc', 'account_id' => '42'],
    ])->assertRedirect(route('companies.e-invoicing.edit', $this->company));

    $integration = $this->company->eInvoicingIntegration()->firstOrFail();
    expect($integration->credentials)->toBe(['api_key' => 'test_abc', 'account_id' => '42']);
    expect($integration->is_active)->toBeTrue();
});

test('blank credential fields keep the stored value', function () {
    EInvoicingIntegration::factory()->create([
        'company_id' => $this->company->id, 'driver' => EInvoicingDriver::B2Brouter,
        'credentials' => ['api_key' => 'old-key', 'account_id' => '42'],
    ]);

    $this->put(route('companies.e-invoicing.update', $this->company), [
        'driver' => 'b2brouter', 'environment' => 'staging', 'is_active' => true,
        'credentials' => ['api_key' => '', 'account_id' => '43'],
    ])->assertSessionHasNoErrors();

    expect($this->company->eInvoicingIntegration()->first()->credentials)->toBe(['api_key' => 'old-key', 'account_id' => '43']);
});

test('switching driver drops the old credentials and validates the new ones', function () {
    EInvoicingIntegration::factory()->create(['company_id' => $this->company->id, 'driver' => EInvoicingDriver::Fake, 'credentials' => ['api_key' => 'stale']]);

    $this->put(route('companies.e-invoicing.update', $this->company), [
        'driver' => 'b2brouter', 'environment' => 'sandbox', 'is_active' => true, 'credentials' => [],
    ])->assertSessionHasErrors(['credentials.api_key', 'credentials.account_id']);
});

test('drivers not supporting the company country are refused', function () {
    $this->company->update(['country_id' => Country::factory()->create(['iso_code' => 'FR'])->id]);

    $this->put(route('companies.e-invoicing.update', $this->company), [
        'driver' => 'b2brouter', 'environment' => 'sandbox', 'is_active' => true,
        'credentials' => ['api_key' => 'k', 'account_id' => '1'],
    ])->assertSessionHasErrors('driver');
});

test('test connection reports a failure', function () {
    EInvoicingIntegration::factory()->create(['company_id' => $this->company->id]);
    FakeProvider::$connectionSucceeds = false;

    $this->post(route('companies.e-invoicing.test', $this->company))
        ->assertRedirect(route('companies.e-invoicing.edit', $this->company));
    // assert the error toast with the same technique used in Task 9
});

test('the desktop build hides the webhook url', function () {
    config(['nativephp-internal.running' => true]);
    EInvoicingIntegration::factory()->create(['company_id' => $this->company->id]);

    $this->get(route('companies.e-invoicing.edit', $this->company))
        ->assertInertia(fn ($page) => $page->where('webhookUrl', null));
});
```

(If Task 11 introduced `App\Support\Desktop`, use it here instead of the config key.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/EInvoicing/EInvoicingSettingsTest.php`
Expected: FAIL.

- [ ] **Step 3: Request**

```php
<?php

namespace App\Http\Requests;

use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEInvoicingIntegrationRequest extends FormRequest
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
        $availableDrivers = array_map(
            fn (EInvoicingDriver $driver): string => $driver->value,
            EInvoicingDriver::availableFor($this->company()->country?->iso_code),
        );

        return [
            'driver' => ['required', 'string', Rule::in($availableDrivers)],
            'environment' => ['required', Rule::enum(EInvoicingEnvironment::class)],
            'is_active' => ['boolean'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Credentials merged with the stored ones (a blank field keeps the stored value) and validated against the driver rules.
     *
     * @return array<string, string>
     */
    public function mergedCredentials(): array
    {
        $driver = EInvoicingDriver::from($this->validated('driver'));
        $existing = $this->company()->eInvoicingIntegration;
        $stored = $existing?->driver === $driver ? ($existing->credentials ?? []) : [];

        $input = array_filter($this->validated('credentials') ?? [], fn (?string $value): bool => filled($value));
        $merged = array_intersect_key([...$stored, ...$input], $driver->credentialRules());

        validator(
            ['credentials' => $merged],
            collect($driver->credentialRules())->mapWithKeys(fn (array $rules, string $key): array => ["credentials.{$key}" => $rules])->all(),
        )->validate();

        return $merged;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->route('company');

        return $company;
    }
}
```

- [ ] **Step 4: Controller and routes**

```php
<?php

namespace App\Http\Controllers;

use App\EInvoicing\EInvoicingProviderFactory;
use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use App\Http\Requests\UpdateEInvoicingIntegrationRequest;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EInvoicingIntegrationController extends Controller
{
    public function edit(Company $company): Response
    {
        $integration = $company->eInvoicingIntegration;

        return Inertia::render('companies/EInvoicing', [
            'company' => ['id' => $company->id, 'name' => $company->name, 'country_iso' => $company->country?->iso_code],
            'drivers' => array_map(fn (EInvoicingDriver $driver): array => [
                'value' => $driver->value,
                'label' => $driver->label(),
                'fields' => $driver->credentialFields(),
            ], EInvoicingDriver::availableFor($company->country?->iso_code)),
            'environments' => array_column(EInvoicingEnvironment::cases(), 'value'),
            'integration' => $integration ? [
                'driver' => $integration->driver->value,
                'environment' => $integration->environment->value,
                'is_active' => $integration->is_active,
                'configured_credentials' => array_keys(array_filter($integration->credentials ?? [], fn (?string $value): bool => filled($value))),
            ] : null,
            'webhookUrl' => $integration && ! config('nativephp-internal.running')
                ? route('einvoicing.webhook', ['driver' => $integration->driver->value, 'integration' => $integration->id, 'secret' => $integration->webhook_secret])
                : null,
        ]);
    }

    public function update(UpdateEInvoicingIntegrationRequest $request, Company $company): RedirectResponse
    {
        $credentials = $request->mergedCredentials();

        $company->eInvoicingIntegration()->updateOrCreate([], [
            'driver' => $request->validated('driver'),
            'environment' => $request->validated('environment'),
            'is_active' => $request->boolean('is_active'),
            'credentials' => $credentials,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Electronic invoicing settings saved.')]);

        return to_route('companies.e-invoicing.edit', $company);
    }

    public function test(Company $company, EInvoicingProviderFactory $factory): RedirectResponse
    {
        $integration = $company->eInvoicingIntegration;

        $succeeded = $integration !== null
            && rescue(fn (): bool => $factory->forIntegration($integration)->testConnection(), false);

        Inertia::flash('toast', $succeeded
            ? ['type' => 'success', 'message' => __('Connection successful.')]
            : ['type' => 'error', 'message' => __('Connection failed: check the credentials and the environment.')]);

        return to_route('companies.e-invoicing.edit', $company);
    }
}
```

The webhook URL carries `?secret=` so it works with B2Brouter even without a signing secret (Task 12 fallback); with a signing secret the query parameter is ignored.

`routes/companies.php` (inside the group, before the resource):

```php
Route::get('companies/{company}/e-invoicing', [EInvoicingIntegrationController::class, 'edit'])->name('companies.e-invoicing.edit');
Route::put('companies/{company}/e-invoicing', [EInvoicingIntegrationController::class, 'update'])->name('companies.e-invoicing.update');
Route::post('companies/{company}/e-invoicing/test', [EInvoicingIntegrationController::class, 'test'])->name('companies.e-invoicing.test');
```

- [ ] **Step 5: Vue page** `resources/js/pages/companies/EInvoicing.vue`

Follow the layout conventions of `companies/Edit.vue` (`Head`, `Heading`, `setLayoutProps` breadcrumbs `companies.index.title` → `companies.eInvoicing.title`, container `max-w-lg space-y-6`).

```vue
<script setup lang="ts">
import { Head, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EInvoicingIntegrationController from '@/actions/App/Http/Controllers/EInvoicingIntegrationController';
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

type CredentialField = {
    name: string;
    type: 'text' | 'password';
    required: boolean;
};

const props = defineProps<{
    company: { id: string; name: string; country_iso: string | null };
    drivers: { value: string; label: string; fields: CredentialField[] }[];
    environments: string[];
    integration: {
        driver: string;
        environment: string;
        is_active: boolean;
        configured_credentials: string[];
    } | null;
    webhookUrl: string | null;
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('companies.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const form = useForm({
    driver: props.integration?.driver ?? props.drivers[0]?.value ?? '',
    environment: props.integration?.environment ?? 'sandbox',
    is_active: props.integration?.is_active ?? false,
    credentials: {} as Record<string, string>,
});

const currentFields = computed(
    () => props.drivers.find((driver) => driver.value === form.driver)?.fields ?? [],
);

const copied = ref(false);

function isConfigured(name: string): boolean {
    return (
        props.integration?.driver === form.driver &&
        props.integration.configured_credentials.includes(name)
    );
}

function submit(): void {
    form.put(EInvoicingIntegrationController.update(props.company.id).url, {
        preserveScroll: true,
        onSuccess: () => form.reset('credentials'),
    });
}

function testConnection(): void {
    router.post(EInvoicingIntegrationController.test(props.company.id).url, {}, { preserveScroll: true });
}

async function copyWebhookUrl(): Promise<void> {
    if (props.webhookUrl) {
        await navigator.clipboard.writeText(props.webhookUrl);
        copied.value = true;
    }
}
</script>

<template>
    <Head :title="t('companies.eInvoicing.title')" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            :title="t('companies.eInvoicing.title')"
            :description="t('companies.eInvoicing.description', { name: company.name })"
        />

        <p v-if="drivers.length === 0" class="text-sm text-muted-foreground">
            {{ t('companies.eInvoicing.unsupportedCountry') }}
        </p>

        <form v-else class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="driver">{{ t('companies.eInvoicing.driver') }}</Label>
                <Select v-model="form.driver">
                    <SelectTrigger id="driver" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="driver in drivers" :key="driver.value" :value="driver.value">
                            {{ driver.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.driver" />
            </div>

            <div class="grid gap-2">
                <Label for="environment">{{ t('companies.eInvoicing.environment') }}</Label>
                <Select v-model="form.environment">
                    <SelectTrigger id="environment" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="environment in environments" :key="environment" :value="environment">
                            {{ t(`companies.eInvoicing.environments.${environment}`) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.environment" />
            </div>

            <div v-for="field in currentFields" :key="field.name" class="grid gap-2">
                <Label :for="`credential_${field.name}`">
                    {{ t(`companies.eInvoicing.credentials.${field.name}`) }}
                </Label>
                <Input
                    :id="`credential_${field.name}`"
                    v-model="form.credentials[field.name]"
                    :type="field.type"
                    autocomplete="off"
                    :placeholder="isConfigured(field.name) ? t('companies.eInvoicing.credentialSet') : ''"
                />
                <InputError :message="form.errors[`credentials.${field.name}`]" />
            </div>

            <div class="flex items-center gap-2">
                <Checkbox id="is_active" v-model="form.is_active" />
                <Label for="is_active">{{ t('companies.eInvoicing.active') }}</Label>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button :disabled="form.processing" type="submit">{{ t('common.actions.save') }}</Button>
                <Button type="button" variant="outline" :disabled="!integration" @click="testConnection">
                    {{ t('companies.eInvoicing.testConnection') }}
                </Button>
            </div>
        </form>

        <div v-if="webhookUrl" class="grid gap-2 border-t pt-6">
            <Label for="webhook_url">{{ t('companies.eInvoicing.webhookUrl') }}</Label>
            <div class="flex gap-2">
                <Input id="webhook_url" :model-value="webhookUrl" readonly />
                <Button type="button" variant="outline" @click="copyWebhookUrl">
                    {{ copied ? t('companies.eInvoicing.copied') : t('companies.eInvoicing.copy') }}
                </Button>
            </div>
            <p class="text-sm text-muted-foreground">{{ t('companies.eInvoicing.webhookHint') }}</p>
        </div>
    </div>
</template>
```

In `companies/Edit.vue`, under the `Heading`, add:

```vue
<Button as-child variant="outline" class="self-start">
    <Link :href="EInvoicingIntegrationController.edit(company.id).url">
        {{ t('companies.eInvoicing.title') }}
    </Link>
</Button>
```

Lang keys (`en`/`it`/`es`) under `companies.eInvoicing`: `title` (`Electronic invoicing` / `Fatturazione elettronica` / `Facturación electrónica`), `description` (`Connect {name} to an accredited e-invoicing provider.`), `driver` (`Provider`), `environment`, `environments.{sandbox,staging,production}`, `active` (`Enabled`), `credentials.{api_key,account_id,webhook_signing_secret}` (`API key`, `Account ID`, `Webhook signing secret`), `credentialSet` (`Saved — leave blank to keep it`), `testConnection`, `webhookUrl`, `webhookHint` (`Register this URL as a webhook in the provider dashboard to receive status updates.`), `copy`, `copied`, `unsupportedCountry` (`Electronic invoicing is available for companies based in Italy or Spain. Set the company country first.`). PHP strings to `it.json`/`es.json`.

- [ ] **Step 6: Run tests and checks**

Run: `php artisan wayfinder:generate && php artisan test --compact tests/Feature/EInvoicing/EInvoicingSettingsTest.php`
Expected: PASS.
Run: `npm run types:check` and `npm run lint:check`.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: company electronic invoicing settings"
```

---

### Task 14: Invoice submission UI (edit panel, index filter and column)

**Files:**
- Create: `resources/js/components/InvoiceSubmissionPanel.vue`
- Modify: `app/Http/Controllers/InvoiceController.php` (`index`, `edit`), `resources/js/pages/invoices/{Edit,Index}.vue`, lang files
- Test: `tests/Feature/EInvoicing/InvoiceSubmissionUiTest.php`

**Interfaces:**
- Consumes: `Invoice::submissions()/latestSubmission()`, routes `invoices.issue`, `invoices.refresh-status`, `resources/js/lib/invoiceStatus.ts`.
- Produces: `edit` props `submissions: list<{id, status, provider_status, authority_id, error_message, submitted_at, completed_at, created_at, events: list<{id, submission_id, type, received_at}>}>` (latest first, max 20) and `requiresSubmission: bool`; `index` accepts `?status=draft|issued`, eager-loads `latestSubmission`, returns `filters.status`.

- [ ] **Step 1: Failing tests** `tests/Feature/EInvoicing/InvoiceSubmissionUiTest.php`

```php
<?php

use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use App\Models\User;

beforeEach(function () {
    $this->company = Company::factory()->create(['is_default' => true]);
    $this->actingAs(User::factory()->create());
});

test('edit exposes the submission history without qr and event payloads', function () {
    $invoice = Invoice::factory()->issued()->create(['company_id' => $this->company->id]);
    $submission = InvoiceSubmission::factory()->for($invoice)->create(['status' => SubmissionStatus::Rejected, 'error_message' => '00404 duplicate', 'qr_code' => 'QRDATA']);
    $submission->events()->create(['type' => 'status_change', 'provider_event_id' => 'e1', 'payload' => ['secret' => 'x'], 'received_at' => now()]);

    $this->get(route('invoices.edit', $invoice))
        ->assertInertia(fn ($page) => $page
            ->where('submissions.0.status', 'rejected')
            ->where('submissions.0.error_message', '00404 duplicate')
            ->where('submissions.0.events.0.type', 'status_change')
            ->missing('submissions.0.qr_code')
            ->missing('submissions.0.events.0.payload'));
});

test('index filters by status and shows the latest submission status', function () {
    Invoice::factory()->create(['company_id' => $this->company->id]);
    $issued = Invoice::factory()->issued()->create(['company_id' => $this->company->id]);
    InvoiceSubmission::factory()->for($issued)->create(['status' => SubmissionStatus::Accepted]);

    $this->get(route('invoices.index', ['status' => 'issued']))
        ->assertInertia(fn ($page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.latest_submission.status', 'accepted')
            ->where('filters.status', 'issued'));
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/EInvoicing/InvoiceSubmissionUiTest.php`
Expected: FAIL.

- [ ] **Step 3: Controller**

`index`:

```php
$status = InvoiceStatus::tryFrom($request->string('status')->toString());

$invoices = Invoice::query()
    ->with(['customer', 'rows', 'latestSubmission'])
    ->where('company_id', $currentCompanyId)
    ->when($status !== null, fn ($query) => $query->where('status', $status))
    // ...existing search, ordering and pagination unchanged...

return Inertia::render('invoices/Index', [
    'invoices' => $invoices,
    'filters' => ['search' => $search, 'status' => $status?->value ?? ''],
]);
```

`InvoiceSubmission` has no hidden attributes, so in the index serialise only the status: add `->through(fn (Invoice $invoice) => [...$invoice->toArray(), 'latest_submission' => $invoice->latestSubmission ? ['status' => $invoice->latestSubmission->status->value] : null])` after `paginate()` — or select `latestSubmission:id,invoice_id,status,created_at` if `latestOfMany` allows column selection in eager loading (check), which also keeps the QR out of the list.

`edit`: add

```php
'submissions' => $invoice->submissions()
    ->with('events:id,submission_id,type,received_at')
    ->latest()
    ->limit(20)
    ->get(['id', 'invoice_id', 'status', 'provider_status', 'authority_id', 'error_message', 'submitted_at', 'completed_at', 'created_at']),
'requiresSubmission' => CountryComplianceResolver::forCompany($invoice->company)->requiresSubmission(),
```

(after the desktop auto-refresh from Task 11 so the list reflects it).

- [ ] **Step 4: `InvoiceSubmissionPanel.vue`**

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import InvoiceSubmissionController from '@/actions/App/Http/Controllers/InvoiceSubmissionController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { InvoiceStatus, SubmissionStatus } from '@/lib/invoiceStatus';
import { submissionStatusVariant } from '@/lib/invoiceStatus';

export type Submission = {
    id: string;
    status: SubmissionStatus;
    provider_status: string | null;
    authority_id: string | null;
    error_message: string | null;
    submitted_at: string | null;
    completed_at: string | null;
    created_at: string;
    events: { id: string; type: string; received_at: string }[];
};

const props = defineProps<{
    invoiceId: string;
    invoiceStatus: InvoiceStatus;
    submissions: Submission[];
}>();

const { t } = useI18n();

const latest = computed(() => props.submissions[0] ?? null);

const alertStatus = computed(() =>
    latest.value && ['failed', 'rejected', 'not_delivered'].includes(latest.value.status)
        ? latest.value.status
        : null,
);

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

function refresh(): void {
    router.post(InvoiceSubmissionController.refresh(props.invoiceId).url, {}, { preserveScroll: true });
}

function retry(): void {
    router.post(InvoiceSubmissionController.issue(props.invoiceId).url, {}, { preserveScroll: true });
}
</script>

<template>
    <section v-if="latest" class="space-y-4 rounded-lg border p-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-medium">{{ t('invoiceSubmissions.title') }}</h2>
            <div class="flex gap-2">
                <Button
                    v-if="latest.status === 'pending' || latest.status === 'submitted'"
                    size="sm"
                    variant="outline"
                    @click="refresh"
                >
                    {{ t('invoiceSubmissions.refresh') }}
                </Button>
                <Button
                    v-if="invoiceStatus === 'draft' && latest.status === 'failed'"
                    size="sm"
                    @click="retry"
                >
                    {{ t('invoiceSubmissions.retry') }}
                </Button>
            </div>
        </div>

        <Alert v-if="alertStatus" :variant="alertStatus === 'not_delivered' ? 'default' : 'destructive'">
            <AlertTitle>{{ t(`invoiceSubmissions.status.${alertStatus}`) }}</AlertTitle>
            <AlertDescription>
                {{ t(`invoiceSubmissions.alerts.${alertStatus}`) }}
                <span v-if="latest.error_message" class="block font-mono text-xs">{{ latest.error_message }}</span>
            </AlertDescription>
        </Alert>

        <ul class="space-y-3 text-sm">
            <li v-for="submission in submissions" :key="submission.id" class="space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :variant="submissionStatusVariant(submission.status)">
                        {{ t(`invoiceSubmissions.status.${submission.status}`) }}
                    </Badge>
                    <span v-if="submission.provider_status" class="text-muted-foreground">{{ submission.provider_status }}</span>
                    <span class="text-muted-foreground">{{ formatDateTime(submission.created_at) }}</span>
                </div>
                <div v-if="submission.authority_id" class="text-muted-foreground">
                    {{ t('invoiceSubmissions.authorityId') }}: <span class="break-all">{{ submission.authority_id }}</span>
                </div>
                <ul v-if="submission.events.length" class="ml-4 list-disc text-xs text-muted-foreground">
                    <li v-for="event in submission.events" :key="event.id">
                        {{ event.type }} — {{ formatDateTime(event.received_at) }}
                    </li>
                </ul>
            </li>
        </ul>
    </section>
</template>
```

(Check `@/components/ui/alert` exports `Alert`, `AlertTitle`, `AlertDescription` and which variants exist; adapt.)

- [ ] **Step 5: Pages**

`invoices/Edit.vue`: props `submissions: Submission[]` (import the type from the panel) and `requiresSubmission: boolean`; render `<InvoiceSubmissionPanel :invoice-id="invoice.id" :invoice-status="invoice.status" :submissions="submissions" />` below the action buttons; the "Issue" button label is `t(requiresSubmission ? 'invoices.edit.issueAndSubmitButton' : 'invoices.edit.issueButton')` (`Issue and submit` / `Emetti e invia` / `Emitir y enviar`).

`invoices/Index.vue`:
- type gains `latest_submission: { status: SubmissionStatus } | null`; `filters.status: string`;
- `const status = ref(props.filters.status);` and a native `select` next to the search box (`''` → `t('invoices.index.statusFilter.all')`, `draft`, `issued`) whose change calls `router.get(index().url, { search: search.value, status: status.value || undefined }, { preserveState: true, replace: true })`; `onSearch` also sends `status`;
- a "Submission" column (`t('invoices.index.columns.submission')`) with `<Badge v-if="invoice.latest_submission" :variant="submissionStatusVariant(invoice.latest_submission.status)">{{ t(`invoiceSubmissions.status.${invoice.latest_submission.status}`) }}</Badge><span v-else>—</span>`.

Lang (`en`/`it`/`es`): `invoiceSubmissions.title` (`Electronic submission` / `Invio elettronico` / `Envío electrónico`), `invoiceSubmissions.status.{pending,failed,submitted,rejected,accepted,delivered,not_delivered}` (it: `In coda`, `Errore`, `Inviata`, `Scartata`, `Accettata`, `Consegnata`, `Non consegnata`; es: `En cola`, `Error`, `Enviada`, `Rechazada`, `Aceptada`, `Entregada`, `No entregada`), `invoiceSubmissions.alerts.failed` (`The provider refused the invoice before it reached the tax authority. Fix the data and issue it again.`), `.alerts.rejected` (`The tax authority rejected the invoice.` — for Italy: `fix it and issue it again within 5 days`; keep a single generic message), `.alerts.not_delivered` (`The invoice was issued but could not be delivered: it is available in the customer's tax area. Send them a courtesy copy by email.`), `invoiceSubmissions.refresh`, `.retry`, `.authorityId`; `invoices.edit.issueAndSubmitButton`; `invoices.index.statusFilter.{all,draft,issued}`; `invoices.index.columns.submission`.

- [ ] **Step 6: Run tests and checks**

Run: `php artisan test --compact tests/Feature/EInvoicing/InvoiceSubmissionUiTest.php tests/Feature/InvoiceTest.php`
Run: `npm run types:check` and `npm run lint:check`
Expected: PASS / no errors.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: invoice submission history and status filter"
```

---

### Task 15: VERI*FACTU QR on the PDF

**Files:**
- Modify: `resources/views/invoices/template.blade.php`, `app/Support/InvoicePdf.php`, `app/Http/Controllers/InvoiceController.php` (`preview`)
- Test: `tests/Feature/InvoiceTemplateTest.php`

**Interfaces:**
- Consumes: `Invoice::latestSubmission`, `InvoiceSubmission::$qr_code`.

- [ ] **Step 1: Failing tests** (append to `InvoiceTemplateTest.php`, following the file's preview pattern)

```php
test('the verifactu qr is printed when present', function () {
    $company = Company::factory()->create(['is_default' => true]);
    $invoice = Invoice::factory()->issued()->create(['company_id' => $company->id]);
    InvoiceSubmission::factory()->for($invoice)->create(['status' => SubmissionStatus::Accepted, 'qr_code' => 'iVBORw0KGgo=']);

    $this->actingAs(User::factory()->create())
        ->get(route('invoices.preview', $invoice))
        ->assertSee('data:image/png;base64,iVBORw0KGgo=', false)
        ->assertSee('VERI*FACTU');
});

test('no qr block without a qr code', function () {
    $company = Company::factory()->create(['is_default' => true]);
    $invoice = Invoice::factory()->issued()->create(['company_id' => $company->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('invoices.preview', $invoice))
        ->assertDontSee('VERI*FACTU');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/InvoiceTemplateTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

Add `'latestSubmission'` to the `load([...])` in `InvoicePdf::render()` and `InvoiceController::preview()`. In the template, after the totals table:

```blade
@if($invoice->latestSubmission?->qr_code)
    <div class="verifactu">
        <img src="data:image/png;base64,{{ $invoice->latestSubmission->qr_code }}" alt="QR" width="120" height="120">
        <div>VERI*FACTU</div>
    </div>
@endif
```

and in the template `<style>`: `.verifactu { margin-top: 16px; font-size: 9px; font-weight: bold; }`.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/InvoiceTemplateTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: print the VERI*FACTU QR on invoices"
```

---

### Task 16: MCP tools

**Files:**
- Create: `app/Mcp/Tools/IssueInvoiceTool.php`
- Modify: `app/Mcp/Servers/BiglinsServer.php`, `app/Mcp/Tools/ListInvoicesTool.php`
- Test: `tests/Feature/Mcp/IssueInvoiceToolTest.php`, `tests/Feature/Mcp/ListInvoicesToolTest.php`, `tests/Feature/Mcp/CreateInvoiceToolTest.php`

**Interfaces:**
- Consumes: `IssueInvoice::handle()`, `CurrentCompany::runningAs()`.
- Produces: tool `issue_invoice` (`company_id`, `invoice_id`) returning `{invoice: {id, number, status, issued_at}, submission: {status, error_message} | null}`; `list_invoices` items gain `status`, `issued_at`, `submission_status`.

- [ ] **Step 1: Failing tests** `tests/Feature/Mcp/IssueInvoiceToolTest.php`

```php
<?php

use App\Enums\InvoiceStatus;
use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\IssueInvoiceTool;
use App\Models\Company;
use App\Models\Country;
use App\Models\Invoice;
use App\Models\InvoiceRow;

test('issue_invoice issues a draft', function () {
    $company = Company::factory()->create(['country_id' => Country::factory()->create(['iso_code' => 'FR'])]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->for($invoice)->create();

    BiglinsServer::tool(IssueInvoiceTool::class, ['company_id' => $company->id, 'invoice_id' => $invoice->id])
        ->assertOk()
        ->assertSee('issued');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
});

test('issue_invoice returns compliance errors', function () {
    $company = Company::factory()->create(['country_id' => Country::factory()->italy(), 'vat_number' => null]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->for($invoice)->create();

    BiglinsServer::tool(IssueInvoiceTool::class, ['company_id' => $company->id, 'invoice_id' => $invoice->id])
        ->assertHasErrors();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});

test('issue_invoice refuses invoices of another company', function () {
    $invoice = Invoice::factory()->create();

    BiglinsServer::tool(IssueInvoiceTool::class, ['company_id' => Company::factory()->create()->id, 'invoice_id' => $invoice->id])
        ->assertHasErrors();
});
```

`ListInvoicesToolTest.php`: add a test creating a draft and an issued invoice with an `Accepted` submission and asserting the response contains `"status":"draft"`, `"status":"issued"` and `"submission_status":"accepted"` (use the assertion style already in the file, e.g. `->assertSee(...)`). `CreateInvoiceToolTest.php`: in the existing success test, assert the created invoice has `status === InvoiceStatus::Draft` and `number === null`.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact tests/Feature/Mcp`
Expected: FAIL.

- [ ] **Step 3: Tool**

```php
<?php

namespace App\Mcp\Tools;

use App\Actions\IssueInvoice;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\CurrentCompany;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('issue_invoice')]
#[Description('Issue a draft invoice: assigns its number, locks it and, for companies in Italy or Spain, submits it to the tax authority through the configured e-invoicing provider.')]
class IssueInvoiceTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $data = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
                'invoice_id' => ['required', 'uuid', Rule::exists('invoices', 'id')->where('company_id', $request->get('company_id'))],
            ]);
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $company = Company::query()->findOrFail($data['company_id']);
        $invoice = Invoice::query()->findOrFail($data['invoice_id']);

        return CurrentCompany::runningAs($company, function () use ($invoice): Response|ResponseFactory {
            try {
                $invoice = app(IssueInvoice::class)->handle($invoice);
            } catch (ValidationException $e) {
                return Response::error(collect($e->errors())->flatten()->implode(' '));
            }

            $submission = $invoice->latestSubmission;

            return Response::structured([
                'invoice' => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'status' => $invoice->status->value,
                    'issued_at' => $invoice->issued_at?->toIso8601String(),
                ],
                'submission' => $submission ? [
                    'status' => $submission->status->value,
                    'error_message' => $submission->error_message,
                ] : null,
            ]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company owning the invoice.')->required(),
            'invoice_id' => $schema->string()->description('UUID of the draft invoice to issue.')->required(),
        ];
    }
}
```

Register it in `BiglinsServer::$tools` after `CreateInvoiceTool::class` and append to the server `#[Instructions]`: `Invoices are created as drafts without a number; use issue_invoice to number, lock and (in Italy and Spain) submit them.`

`ListInvoicesTool`: `->with(['customer', 'latestSubmission'])`, add `'status', 'issued_at'` to the `get()` columns, and to the mapped array:

```php
'status' => $invoice->status->value,
'issued_at' => $invoice->issued_at?->toIso8601String(),
'submission_status' => $invoice->latestSubmission?->status->value,
```

Also update its `->orderByDesc('number')` the same way as the web index (drafts first).

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Mcp`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: issue_invoice MCP tool and submission status in list_invoices"
```

---

### Task 17: Final verification

- [ ] **Step 1:** `vendor/bin/pint --format agent` — nothing left to fix.
- [ ] **Step 2:** `php artisan test --compact` — full suite green.
- [ ] **Step 3:** `vendor/bin/phpstan analyse` (Larastan, if `phpstan.neon` exists) — no new errors.
- [ ] **Step 4:** `npm run types:check`, `npm run lint:check`, `npm run format:check`, `npm run build` — all green.
- [ ] **Step 5:** Manual smoke test with `composer run dev`: an Italian and a Spanish company with the `Fake` driver; edit fiscal data (fiscal section changes with the country); create an invoice with a 0% row and an exemption code; issue it; check badges, submission panel, lock, PDF and preview of a draft; settings page (blank credential keeps the value, test connection).
- [ ] **Step 6:** Commit any fixes, then use superpowers:finishing-a-development-branch.
