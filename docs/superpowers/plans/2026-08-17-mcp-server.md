# MCP Server Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose Biglins (customers, estimations, invoices, send-by-email) to AI agents through an MCP server, reachable both as a local/stdio process (NativePHP desktop build) and as an authenticated HTTP endpoint (Docker deployment, potentially internet-exposed).

**Architecture:** A single `App\Mcp\Servers\BiglinsServer` and a set of `App\Mcp\Tools\*` classes are shared between both transports. Each tool takes an explicit `company_id` argument (there is no HTTP session in either transport) and reuses the app's existing `StoreXRequest`/`SendXRequest` validation rule arrays via a new `CurrentCompany::runningAs()` override, so business-rule validation (e.g. "customer_id must belong to this company") isn't duplicated. The web transport is protected by Sanctum personal access tokens, managed from a new Settings > API Tokens page.

**Tech Stack:** Laravel 13, `laravel/mcp` (promoted from dev-only to a real dependency), `laravel/sanctum` (new dependency), Pest, Inertia + Vue 3.

**Spec:** `docs/superpowers/specs/2026-08-17-mcp-server-design.md`

## Global Constraints

- Tool names use snake_case (`list_customers`, `create_customer`, ...), not the package's default kebab-case-from-classname.
- Every write tool validates `company_id` first (`required|uuid|exists:companies,id`), resolves the `Company`, then runs its business logic inside `CurrentCompany::runningAs($company, fn () => ...)`.
- Business-rule validation inside a tool reuses the corresponding `App\Http\Requests\*Request::rules()` array via `$request->validate(...)` — never re-declare those rules by hand.
- `ValidationException` from `$request->validate(...)` is always caught in the tool and turned into `Response::error($e->validator->errors()->first())` — an uncaught exception would surface as a raw JSON-RPC protocol error instead of a normal tool-level error the agent can read.
- List tools and successful writes return `Response::structured([...])` (not the `@internal`-flagged `Response::json()`), so tests can use `assertStructuredContent(...)`.
- Every new PHP file follows existing conventions: explicit return types, PHPDoc array shapes, curly braces on all control structures.
- Run `vendor/bin/pint --dirty --format agent` after any task that touches PHP, before committing.

---

### Task 1: `CurrentCompany::runningAs()` override

**Files:**
- Modify: `app/Support/CurrentCompany.php`
- Test: `tests/Feature/CurrentCompanyTest.php`

**Interfaces:**
- Produces: `CurrentCompany::runningAs(Company $company, Closure $callback): mixed` — every later task's tools call this to scope their logic to an explicit company outside the HTTP session.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/CurrentCompanyTest.php`:

```php
test('runningAs overrides resolve for the duration of the closure', function () {
    $sessionCompany = Company::factory()->create();
    $overrideCompany = Company::factory()->create();
    session(['current_company_id' => $sessionCompany->id]);

    $resolvedDuring = CurrentCompany::runningAs($overrideCompany, fn () => CurrentCompany::resolve()?->id);

    expect($resolvedDuring)->toBe($overrideCompany->id);
    expect(CurrentCompany::resolve()->id)->toBe($sessionCompany->id);
});

test('runningAs restores the previous override when the closure throws', function () {
    $company = Company::factory()->create();

    expect(fn () => CurrentCompany::runningAs($company, function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(CurrentCompany::resolve())->not->toBe($company);
});

test('runningAs nests correctly, restoring the outer override on exit', function () {
    $outer = Company::factory()->create();
    $inner = Company::factory()->create();

    $result = CurrentCompany::runningAs($outer, function () use ($inner) {
        $duringInner = CurrentCompany::runningAs($inner, fn () => CurrentCompany::resolve()->id);

        return [$duringInner, CurrentCompany::resolve()->id];
    });

    expect($result)->toBe([$inner->id, $outer->id]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=CurrentCompanyTest`
Expected: FAIL — `Call to undefined method App\Support\CurrentCompany::runningAs()`

- [ ] **Step 3: Implement `runningAs()`**

Replace the full contents of `app/Support/CurrentCompany.php`:

```php
<?php

namespace App\Support;

use App\Models\Company;
use Closure;
use SortDirection;

class CurrentCompany
{
    private static ?Company $override = null;

    public static function resolve(): ?Company
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $sessionId = session('current_company_id');

        if (is_string($sessionId)) {
            $company = Company::query()->find($sessionId);

            if ($company !== null) {
                return $company;
            }
        }

        return Company::query()->where('is_default', true)->first()
            ?? Company::query()->orderBy('name', SortDirection::Ascending)->first();
    }

    public static function runningAs(Company $company, Closure $callback): mixed
    {
        $previous = self::$override;
        self::$override = $company;

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=CurrentCompanyTest`
Expected: PASS (all tests in the file, including the pre-existing ones)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/CurrentCompany.php tests/Feature/CurrentCompanyTest.php
git commit -m "feat: add CurrentCompany::runningAs() for non-HTTP company scoping"
```

---

### Task 2: MCP server foundation + `list_customers`

**Files:**
- Create: `app/Mcp/Servers/BiglinsServer.php`
- Create: `app/Mcp/Tools/ListCustomersTool.php`
- Modify: `routes/ai.php`
- Modify: `composer.json` (move `laravel/mcp` to `require`)
- Test: `tests/Feature/Mcp/ListCustomersToolTest.php`

**Interfaces:**
- Produces: `App\Mcp\Servers\BiglinsServer` (the `$tools` array later tasks append to), the pattern every later tool follows (validate `company_id` → `Response::error` on failure → query → `Response::structured`).

- [ ] **Step 1: Promote `laravel/mcp` to a real dependency**

`laravel/mcp` is currently only in `composer.lock` as a transitive dev dependency of `laravel/boost`. Run:

```bash
composer require laravel/mcp
```

Composer resolves this against the already-locked version — expect no version change, just `composer.json`'s `require` section gaining `"laravel/mcp": "^0.9.1"` (or whatever the installed version is) and `composer.lock`'s `packages`/`packages-dev` split updating.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Mcp/ListCustomersToolTest.php`:

```php
<?php

use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\ListCustomersTool;
use App\Models\Company;
use App\Models\Customer;

test('list_customers returns customers scoped to the given company', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Customer::factory()->count(2)->create(['company_id' => $company->id]);
    Customer::factory()->create(['company_id' => $otherCompany->id]);

    $response = BiglinsServer::tool(ListCustomersTool::class, [
        'company_id' => $company->id,
    ]);

    $response->assertOk();
    $response->assertStructuredContent(fn ($json) => $json->count('customers', 2)->etc());
});

test('list_customers filters by the search term', function () {
    $company = Company::factory()->create();
    Customer::factory()->create(['company_id' => $company->id, 'name' => 'Acme Corp']);
    Customer::factory()->create(['company_id' => $company->id, 'name' => 'Globex Inc']);

    $response = BiglinsServer::tool(ListCustomersTool::class, [
        'company_id' => $company->id,
        'search' => 'Acme',
    ]);

    $response->assertOk();
    $response->assertStructuredContent(fn ($json) => $json
        ->count('customers', 1)
        ->where('customers.0.name', 'Acme Corp')
        ->etc());
});

test('list_customers rejects a company_id that does not exist', function () {
    $response = BiglinsServer::tool(ListCustomersTool::class, [
        'company_id' => (string) Illuminate\Support\Str::uuid(),
    ]);

    $response->assertHasErrors();
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact --filter=ListCustomersToolTest`
Expected: FAIL — class `App\Mcp\Servers\BiglinsServer` not found

- [ ] **Step 4: Scaffold the server**

Create `app/Mcp/Servers/BiglinsServer.php`:

```php
<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ListCustomersTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Biglins')]
#[Version('1.0.0')]
#[Instructions('Manage customers, estimations, and invoices for a Biglins company, and send them by email. Every tool takes an explicit company_id — use list_customers/list_estimations/list_invoices to discover existing records before creating new ones.')]
class BiglinsServer extends Server
{
    protected array $tools = [
        ListCustomersTool::class,
    ];
}
```

- [ ] **Step 5: Write the tool**

Create `app/Mcp/Tools/ListCustomersTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Customer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_customers')]
#[Description('List customers for a given company, optionally filtered by a search term matched against name or email.')]
class ListCustomersTool extends Tool
{
    public function handle(Request $request): Response
    {
        try {
            $data = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
                'search' => ['nullable', 'string'],
            ]);
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $search = trim($data['search'] ?? '');

        $customers = Customer::query()
            ->where('company_id', $data['company_id'])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'email', 'city', 'country_id']);

        return Response::structured([
            'customers' => $customers->toArray(),
            'truncated' => $customers->count() === 50,
        ]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company to list customers for.')->required(),
            'search' => $schema->string()->description('Optional filter matched against customer name or email.'),
        ];
    }
}
```

- [ ] **Step 6: Register the local transport**

Replace the contents of `routes/ai.php`:

```php
<?php

use App\Mcp\Servers\BiglinsServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('biglins', BiglinsServer::class);
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --compact --filter=ListCustomersToolTest`
Expected: PASS

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock app/Mcp routes/ai.php tests/Feature/Mcp/ListCustomersToolTest.php
git commit -m "feat: scaffold MCP server foundation with list_customers tool"
```

---

### Task 3: `create_customer` tool

**Files:**
- Create: `app/Mcp/Tools/CreateCustomerTool.php`
- Modify: `app/Mcp/Servers/BiglinsServer.php` (register the tool)
- Test: `tests/Feature/Mcp/CreateCustomerToolTest.php`

**Interfaces:**
- Consumes: `CurrentCompany::runningAs()` from Task 1.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Mcp/CreateCustomerToolTest.php`:

```php
<?php

use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\CreateCustomerTool;
use App\Models\Company;
use App\Models\Customer;

test('create_customer creates a customer scoped to the given company', function () {
    $company = Company::factory()->create();

    $response = BiglinsServer::tool(CreateCustomerTool::class, [
        'company_id' => $company->id,
        'name' => 'Acme Corp',
        'email' => 'billing@acme.test',
    ]);

    $response->assertOk();
    $customer = Customer::query()->where('name', 'Acme Corp')->firstOrFail();
    expect($customer->company_id)->toBe($company->id);
    expect($customer->email)->toBe('billing@acme.test');
});

test('create_customer requires a name', function () {
    $company = Company::factory()->create();

    $response = BiglinsServer::tool(CreateCustomerTool::class, [
        'company_id' => $company->id,
        'name' => '',
    ]);

    $response->assertHasErrors();
    expect(Customer::query()->where('company_id', $company->id)->exists())->toBeFalse();
});

test('create_customer rejects an invalid email', function () {
    $company = Company::factory()->create();

    $response = BiglinsServer::tool(CreateCustomerTool::class, [
        'company_id' => $company->id,
        'name' => 'Acme Corp',
        'email' => 'not-an-email',
    ]);

    $response->assertHasErrors();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=CreateCustomerToolTest`
Expected: FAIL — class `App\Mcp\Tools\CreateCustomerTool` not found

- [ ] **Step 3: Write the tool**

Create `app/Mcp/Tools/CreateCustomerTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Http\Requests\StoreCustomerRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Support\CurrentCompany;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_customer')]
#[Description('Create a new customer under a given company.')]
class CreateCustomerTool extends Tool
{
    public function handle(Request $request): Response
    {
        try {
            $companyId = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            ])['company_id'];
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $company = Company::query()->findOrFail($companyId);

        return CurrentCompany::runningAs($company, function () use ($request, $company): Response {
            try {
                $data = $request->validate((new StoreCustomerRequest())->rules());
            } catch (ValidationException $e) {
                return Response::error($e->validator->errors()->first());
            }

            $customer = Customer::query()->create([
                ...$data,
                'company_id' => $company->id,
            ]);

            return Response::structured(['customer' => $customer->toArray()]);
        });
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company to create the customer under.')->required(),
            'name' => $schema->string()->description('Customer name.')->required(),
            'address' => $schema->string()->description('Street address.'),
            'zip' => $schema->string()->description('Postal code.'),
            'city' => $schema->string(),
            'country_id' => $schema->string()->description('UUID of an existing country.'),
            'state' => $schema->string()->description('State or province.'),
            'email' => $schema->string()->format('email'),
            'web' => $schema->string()->format('uri'),
            'phone' => $schema->string(),
            'nif' => $schema->string()->description('Tax identification number.'),
        ];
    }
}
```

- [ ] **Step 4: Register the tool in the server**

In `app/Mcp/Servers/BiglinsServer.php`, add the import `use App\Mcp\Tools\CreateCustomerTool;` and add `CreateCustomerTool::class,` to the `$tools` array (after `ListCustomersTool::class`).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=CreateCustomerToolTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mcp tests/Feature/Mcp/CreateCustomerToolTest.php
git commit -m "feat: add create_customer MCP tool"
```

---

### Task 4: `list_estimations` tool

**Files:**
- Create: `app/Mcp/Tools/ListEstimationsTool.php`
- Modify: `app/Mcp/Servers/BiglinsServer.php`
- Test: `tests/Feature/Mcp/ListEstimationsToolTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Mcp/ListEstimationsToolTest.php`:

```php
<?php

use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\ListEstimationsTool;
use App\Models\Company;
use App\Models\Estimation;

test('list_estimations returns estimations scoped to the given company', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Estimation::factory()->count(2)->create(['company_id' => $company->id]);
    Estimation::factory()->create(['company_id' => $otherCompany->id]);

    $response = BiglinsServer::tool(ListEstimationsTool::class, [
        'company_id' => $company->id,
    ]);

    $response->assertOk();
    $response->assertStructuredContent(fn ($json) => $json->count('estimations', 2)->etc());
});

test('list_estimations rejects a company_id that does not exist', function () {
    $response = BiglinsServer::tool(ListEstimationsTool::class, [
        'company_id' => (string) Illuminate\Support\Str::uuid(),
    ]);

    $response->assertHasErrors();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=ListEstimationsToolTest`
Expected: FAIL — class `App\Mcp\Tools\ListEstimationsTool` not found

- [ ] **Step 3: Write the tool**

Create `app/Mcp/Tools/ListEstimationsTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Estimation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_estimations')]
#[Description('List estimations for a given company.')]
class ListEstimationsTool extends Tool
{
    public function handle(Request $request): Response
    {
        try {
            $companyId = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            ])['company_id'];
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $estimations = Estimation::query()
            ->with('customer')
            ->where('company_id', $companyId)
            ->orderByDesc('number')
            ->limit(50)
            ->get(['id', 'number', 'customer_id', 'estimation_date', 'expiration_date', 'status'])
            ->map(fn (Estimation $estimation): array => [
                'id' => $estimation->id,
                'number' => $estimation->number,
                'customer_id' => $estimation->customer_id,
                'customer_name' => $estimation->customer->name,
                'estimation_date' => $estimation->estimation_date->format('Y-m-d'),
                'expiration_date' => $estimation->expiration_date->format('Y-m-d'),
                'status' => $estimation->status->value,
            ]);

        return Response::structured([
            'estimations' => $estimations->toArray(),
            'truncated' => $estimations->count() === 50,
        ]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company to list estimations for.')->required(),
        ];
    }
}
```

- [ ] **Step 4: Register the tool**

In `app/Mcp/Servers/BiglinsServer.php`, add `use App\Mcp\Tools\ListEstimationsTool;` and append `ListEstimationsTool::class,` to `$tools`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=ListEstimationsToolTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mcp tests/Feature/Mcp/ListEstimationsToolTest.php
git commit -m "feat: add list_estimations MCP tool"
```

---

### Task 5: `create_estimation` tool

**Files:**
- Create: `app/Mcp/Tools/CreateEstimationTool.php`
- Modify: `app/Mcp/Servers/BiglinsServer.php`
- Test: `tests/Feature/Mcp/CreateEstimationToolTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Mcp/CreateEstimationToolTest.php`:

```php
<?php

use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\CreateEstimationTool;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimation;

test('create_estimation creates an estimation with rows scoped to the given company', function () {
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id]);

    $response = BiglinsServer::tool(CreateEstimationTool::class, [
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'estimation_date' => '2026-08-17',
        'expiration_date' => '2026-09-17',
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 2, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertOk();
    $estimation = Estimation::query()->where('company_id', $company->id)->firstOrFail();
    expect($estimation->customer_id)->toBe($customer->id);
    expect($estimation->rows)->toHaveCount(1);
    expect((float) $estimation->rows->first()->price)->toBe(100.0);
});

test('create_estimation rejects a customer_id belonging to another company', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $otherCompany->id]);

    $response = BiglinsServer::tool(CreateEstimationTool::class, [
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'estimation_date' => '2026-08-17',
        'expiration_date' => '2026-09-17',
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertHasErrors();
    expect(Estimation::query()->where('company_id', $company->id)->exists())->toBeFalse();
});

test('create_estimation requires at least one row', function () {
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id]);

    $response = BiglinsServer::tool(CreateEstimationTool::class, [
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'estimation_date' => '2026-08-17',
        'expiration_date' => '2026-09-17',
        'language' => 'en',
        'rows' => [],
    ]);

    $response->assertHasErrors();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=CreateEstimationToolTest`
Expected: FAIL — class `App\Mcp\Tools\CreateEstimationTool` not found

- [ ] **Step 3: Write the tool**

Create `app/Mcp/Tools/CreateEstimationTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Http\Requests\StoreEstimationRequest;
use App\Models\Company;
use App\Models\Estimation;
use App\Support\CurrentCompany;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_estimation')]
#[Description('Create a new estimation with line items for an existing customer.')]
class CreateEstimationTool extends Tool
{
    public function handle(Request $request): Response
    {
        try {
            $companyId = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            ])['company_id'];
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $company = Company::query()->findOrFail($companyId);

        return CurrentCompany::runningAs($company, function () use ($request, $company): Response {
            try {
                $data = $request->validate((new StoreEstimationRequest())->rules());
            } catch (ValidationException $e) {
                return Response::error($e->validator->errors()->first());
            }

            $estimation = DB::transaction(function () use ($data, $company): Estimation {
                $estimation = Estimation::query()->create([
                    ...collect($data)->except('rows')->all(),
                    'company_id' => $company->id,
                ]);

                $estimation->rows()->createMany($data['rows']);

                return $estimation;
            });

            return Response::structured(['estimation' => $estimation->load('rows')->toArray()]);
        });
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company to create the estimation under.')->required(),
            'customer_id' => $schema->string()->description('UUID of an existing customer belonging to the company.')->required(),
            'estimation_date' => $schema->string()->format('date')->description('YYYY-MM-DD.')->required(),
            'expiration_date' => $schema->string()->format('date')->description('YYYY-MM-DD, must be on or after estimation_date.')->required(),
            'language' => $schema->string()->enum(['it', 'en', 'es'])->required(),
            'body' => $schema->string()->description('Optional markdown body/notes.'),
            'rows' => $schema->array()->items(
                $schema->object([
                    'description' => $schema->string()->required(),
                    'quantity' => $schema->number()->required(),
                    'price' => $schema->number()->required(),
                    'vat_rate' => $schema->number()->required(),
                    'note' => $schema->string(),
                ])
            )->description('Line items, at least one required.')->required(),
        ];
    }
}
```

- [ ] **Step 4: Register the tool**

In `app/Mcp/Servers/BiglinsServer.php`, add `use App\Mcp\Tools\CreateEstimationTool;` and append `CreateEstimationTool::class,` to `$tools`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=CreateEstimationToolTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mcp tests/Feature/Mcp/CreateEstimationToolTest.php
git commit -m "feat: add create_estimation MCP tool"
```

---

### Task 6: `list_invoices` tool

**Files:**
- Create: `app/Mcp/Tools/ListInvoicesTool.php`
- Modify: `app/Mcp/Servers/BiglinsServer.php`
- Test: `tests/Feature/Mcp/ListInvoicesToolTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Mcp/ListInvoicesToolTest.php`:

```php
<?php

use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\ListInvoicesTool;
use App\Models\Company;
use App\Models\Invoice;

test('list_invoices returns invoices scoped to the given company', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Invoice::factory()->count(2)->create(['company_id' => $company->id]);
    Invoice::factory()->create(['company_id' => $otherCompany->id]);

    $response = BiglinsServer::tool(ListInvoicesTool::class, [
        'company_id' => $company->id,
    ]);

    $response->assertOk();
    $response->assertStructuredContent(fn ($json) => $json->count('invoices', 2)->etc());
});

test('list_invoices rejects a company_id that does not exist', function () {
    $response = BiglinsServer::tool(ListInvoicesTool::class, [
        'company_id' => (string) Illuminate\Support\Str::uuid(),
    ]);

    $response->assertHasErrors();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=ListInvoicesToolTest`
Expected: FAIL — class `App\Mcp\Tools\ListInvoicesTool` not found

- [ ] **Step 3: Write the tool**

Create `app/Mcp/Tools/ListInvoicesTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\Invoice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_invoices')]
#[Description('List invoices for a given company.')]
class ListInvoicesTool extends Tool
{
    public function handle(Request $request): Response
    {
        try {
            $companyId = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            ])['company_id'];
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $invoices = Invoice::query()
            ->with('customer')
            ->where('company_id', $companyId)
            ->orderByDesc('number')
            ->limit(50)
            ->get(['id', 'number', 'type', 'customer_id', 'invoice_date', 'paid'])
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'type' => $invoice->type->value,
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer->name,
                'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
                'paid' => $invoice->paid,
            ]);

        return Response::structured([
            'invoices' => $invoices->toArray(),
            'truncated' => $invoices->count() === 50,
        ]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company to list invoices for.')->required(),
        ];
    }
}
```

- [ ] **Step 4: Register the tool**

In `app/Mcp/Servers/BiglinsServer.php`, add `use App\Mcp\Tools\ListInvoicesTool;` and append `ListInvoicesTool::class,` to `$tools`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=ListInvoicesToolTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mcp tests/Feature/Mcp/ListInvoicesToolTest.php
git commit -m "feat: add list_invoices MCP tool"
```

---

### Task 7: `create_invoice` tool

**Files:**
- Create: `app/Mcp/Tools/CreateInvoiceTool.php`
- Modify: `app/Mcp/Servers/BiglinsServer.php`
- Test: `tests/Feature/Mcp/CreateInvoiceToolTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Mcp/CreateInvoiceToolTest.php`:

```php
<?php

use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\CreateInvoiceTool;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;

test('create_invoice creates an invoice with rows scoped to the given company', function () {
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id]);

    $response = BiglinsServer::tool(CreateInvoiceTool::class, [
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'invoice_date' => '2026-08-17',
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 200, 'vat_rate' => 22],
        ],
    ]);

    $response->assertOk();
    $invoice = Invoice::query()->where('company_id', $company->id)->firstOrFail();
    expect($invoice->customer_id)->toBe($customer->id);
    expect((float) $invoice->rows->first()->price)->toBe(200.0);
});

test('create_invoice negates row prices for a credit note', function () {
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id]);

    $response = BiglinsServer::tool(CreateInvoiceTool::class, [
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'type' => 'credit_note',
        'invoice_date' => '2026-08-17',
        'language' => 'en',
        'rows' => [
            ['description' => 'Refund', 'quantity' => 1, 'price' => 50, 'vat_rate' => 22],
        ],
    ]);

    $response->assertOk();
    $invoice = Invoice::query()->where('company_id', $company->id)->firstOrFail();
    expect((float) $invoice->rows->first()->price)->toBe(-50.0);
});

test('create_invoice rejects a customer_id belonging to another company', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $otherCompany->id]);

    $response = BiglinsServer::tool(CreateInvoiceTool::class, [
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'invoice_date' => '2026-08-17',
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertHasErrors();
    expect(Invoice::query()->where('company_id', $company->id)->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=CreateInvoiceToolTest`
Expected: FAIL — class `App\Mcp\Tools\CreateInvoiceTool` not found

- [ ] **Step 3: Write the tool**

Create `app/Mcp/Tools/CreateInvoiceTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Enums\InvoiceType;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\CurrentCompany;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_invoice')]
#[Description('Create a new invoice (or credit note) with line items for an existing customer.')]
class CreateInvoiceTool extends Tool
{
    public function handle(Request $request): Response
    {
        try {
            $companyId = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            ])['company_id'];
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $company = Company::query()->findOrFail($companyId);

        return CurrentCompany::runningAs($company, function () use ($request, $company): Response {
            try {
                $data = $request->validate((new StoreInvoiceRequest())->rules());
            } catch (ValidationException $e) {
                return Response::error($e->validator->errors()->first());
            }

            $invoice = DB::transaction(function () use ($data, $company): Invoice {
                $invoice = Invoice::query()->create([
                    ...collect($data)->except('rows')->all(),
                    'company_id' => $company->id,
                ]);

                $type = $invoice->type;
                $rows = collect($data['rows'])->map(function (array $row) use ($type): array {
                    if ($type === InvoiceType::CreditNote) {
                        $row['price'] = -abs((float) $row['price']);
                    }

                    return $row;
                });

                $invoice->rows()->createMany($rows);

                return $invoice;
            });

            return Response::structured(['invoice' => $invoice->load('rows')->toArray()]);
        });
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company to create the invoice under.')->required(),
            'customer_id' => $schema->string()->description('UUID of an existing customer belonging to the company.')->required(),
            'type' => $schema->string()->enum(['invoice', 'credit_note'])->description('Defaults to invoice.'),
            'invoice_date' => $schema->string()->format('date')->description('YYYY-MM-DD.')->required(),
            'note' => $schema->string(),
            'language' => $schema->string()->enum(['it', 'en', 'es'])->required(),
            'rows' => $schema->array()->items(
                $schema->object([
                    'description' => $schema->string()->required(),
                    'quantity' => $schema->number()->required(),
                    'price' => $schema->number()->required(),
                    'vat_rate' => $schema->number()->required(),
                    'expiration_date' => $schema->string()->format('date'),
                ])
            )->description('Line items, at least one required.')->required(),
        ];
    }
}
```

- [ ] **Step 4: Register the tool**

In `app/Mcp/Servers/BiglinsServer.php`, add `use App\Mcp\Tools\CreateInvoiceTool;` and append `CreateInvoiceTool::class,` to `$tools`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=CreateInvoiceToolTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mcp tests/Feature/Mcp/CreateInvoiceToolTest.php
git commit -m "feat: add create_invoice MCP tool"
```

---

### Task 8: `send_invoice_email` tool

**Files:**
- Create: `app/Mcp/Tools/SendInvoiceEmailTool.php`
- Modify: `app/Mcp/Servers/BiglinsServer.php`
- Test: `tests/Feature/Mcp/SendInvoiceEmailToolTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Mcp/SendInvoiceEmailToolTest.php`:

```php
<?php

use App\Mail\InvoiceMail;
use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\SendInvoiceEmailTool;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Facades\Mail;

test('send_invoice_email sends the invoice and records who it was sent to', function () {
    Mail::fake();
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id]);

    $response = BiglinsServer::tool(SendInvoiceEmailTool::class, [
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'to' => 'client@example.test',
        'subject' => 'Your invoice',
        'message' => 'Please find your invoice attached.',
    ]);

    $response->assertOk();
    Mail::assertSent(InvoiceMail::class);
    expect($invoice->fresh()->sent_to)->toBe('client@example.test');
    expect($invoice->fresh()->sent_at)->not->toBeNull();
});

test('send_invoice_email rejects an invoice belonging to another company', function () {
    Mail::fake();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);

    $response = BiglinsServer::tool(SendInvoiceEmailTool::class, [
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'to' => 'client@example.test',
        'subject' => 'Your invoice',
        'message' => 'Please find your invoice attached.',
    ]);

    $response->assertHasErrors();
    Mail::assertNotSent(InvoiceMail::class);
});

test('send_invoice_email requires a valid recipient email', function () {
    Mail::fake();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);

    $response = BiglinsServer::tool(SendInvoiceEmailTool::class, [
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'to' => 'not-an-email',
        'subject' => 'Your invoice',
        'message' => 'Please find your invoice attached.',
    ]);

    $response->assertHasErrors();
    Mail::assertNotSent(InvoiceMail::class);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=SendInvoiceEmailToolTest`
Expected: FAIL — class `App\Mcp\Tools\SendInvoiceEmailTool` not found

- [ ] **Step 3: Write the tool**

Create `app/Mcp/Tools/SendInvoiceEmailTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Http\Requests\SendInvoiceRequest;
use App\Mail\InvoiceMail;
use App\Models\Invoice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('send_invoice_email')]
#[Description('Email an existing invoice as a PDF attachment to a recipient.')]
class SendInvoiceEmailTool extends Tool
{
    public function handle(Request $request): Response
    {
        try {
            $data = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
                'invoice_id' => ['required', 'uuid'],
            ]);
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $invoice = Invoice::query()->where('company_id', $data['company_id'])->find($data['invoice_id']);

        if ($invoice === null) {
            return Response::error('No invoice with that id was found for the given company.');
        }

        try {
            $mailData = $request->validate((new SendInvoiceRequest())->rules());
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        Mail::to($mailData['to'])->send(new InvoiceMail($invoice, $mailData['subject'], $mailData['message']));

        $invoice->sent_at = Carbon::now();
        $invoice->sent_to = $mailData['to'];
        $invoice->save();

        return Response::structured(['invoice' => $invoice->fresh()->toArray()]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company the invoice belongs to.')->required(),
            'invoice_id' => $schema->string()->description('UUID of the invoice to send.')->required(),
            'to' => $schema->string()->format('email')->required(),
            'subject' => $schema->string()->required(),
            'message' => $schema->string()->description('Email body.')->required(),
        ];
    }
}
```

- [ ] **Step 4: Register the tool**

In `app/Mcp/Servers/BiglinsServer.php`, add `use App\Mcp\Tools\SendInvoiceEmailTool;` and append `SendInvoiceEmailTool::class,` to `$tools`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=SendInvoiceEmailToolTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mcp tests/Feature/Mcp/SendInvoiceEmailToolTest.php
git commit -m "feat: add send_invoice_email MCP tool"
```

---

### Task 9: `send_estimation_email` tool

**Files:**
- Create: `app/Mcp/Tools/SendEstimationEmailTool.php`
- Modify: `app/Mcp/Servers/BiglinsServer.php`
- Test: `tests/Feature/Mcp/SendEstimationEmailToolTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Mcp/SendEstimationEmailToolTest.php`:

```php
<?php

use App\Mail\EstimationMail;
use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\SendEstimationEmailTool;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimation;
use Illuminate\Support\Facades\Mail;

test('send_estimation_email sends the estimation and records who it was sent to', function () {
    Mail::fake();
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id]);
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id]);

    $response = BiglinsServer::tool(SendEstimationEmailTool::class, [
        'company_id' => $company->id,
        'estimation_id' => $estimation->id,
        'to' => 'client@example.test',
        'subject' => 'Your estimation',
        'message' => 'Please find your estimation attached.',
    ]);

    $response->assertOk();
    Mail::assertSent(EstimationMail::class);
    expect($estimation->fresh()->sent_to)->toBe('client@example.test');
    expect($estimation->fresh()->sent_at)->not->toBeNull();
});

test('send_estimation_email rejects an estimation belonging to another company', function () {
    Mail::fake();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $otherCompany->id]);

    $response = BiglinsServer::tool(SendEstimationEmailTool::class, [
        'company_id' => $company->id,
        'estimation_id' => $estimation->id,
        'to' => 'client@example.test',
        'subject' => 'Your estimation',
        'message' => 'Please find your estimation attached.',
    ]);

    $response->assertHasErrors();
    Mail::assertNotSent(EstimationMail::class);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=SendEstimationEmailToolTest`
Expected: FAIL — class `App\Mcp\Tools\SendEstimationEmailTool` not found

- [ ] **Step 3: Write the tool**

Create `app/Mcp/Tools/SendEstimationEmailTool.php`:

```php
<?php

namespace App\Mcp\Tools;

use App\Http\Requests\SendEstimationRequest;
use App\Mail\EstimationMail;
use App\Models\Estimation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('send_estimation_email')]
#[Description('Email an existing estimation to a recipient.')]
class SendEstimationEmailTool extends Tool
{
    public function handle(Request $request): Response
    {
        try {
            $data = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
                'estimation_id' => ['required', 'uuid'],
            ]);
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $estimation = Estimation::query()->where('company_id', $data['company_id'])->find($data['estimation_id']);

        if ($estimation === null) {
            return Response::error('No estimation with that id was found for the given company.');
        }

        try {
            $mailData = $request->validate((new SendEstimationRequest())->rules());
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        Mail::to($mailData['to'])->send(new EstimationMail($estimation, $mailData['subject'], $mailData['message']));

        $estimation->sent_at = Carbon::now();
        $estimation->sent_to = $mailData['to'];
        $estimation->save();

        return Response::structured(['estimation' => $estimation->fresh()->toArray()]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company the estimation belongs to.')->required(),
            'estimation_id' => $schema->string()->description('UUID of the estimation to send.')->required(),
            'to' => $schema->string()->format('email')->required(),
            'subject' => $schema->string()->required(),
            'message' => $schema->string()->description('Email body.')->required(),
        ];
    }
}
```

- [ ] **Step 4: Register the tool**

In `app/Mcp/Servers/BiglinsServer.php`, add `use App\Mcp\Tools\SendEstimationEmailTool;` and append `SendEstimationEmailTool::class,` to `$tools`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=SendEstimationEmailToolTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mcp tests/Feature/Mcp/SendEstimationEmailToolTest.php
git commit -m "feat: add send_estimation_email MCP tool"
```

---

### Task 10: Sanctum + web transport

**Files:**
- Modify: `composer.json` (add `laravel/sanctum`)
- Modify: `config/auth.php` (register the `sanctum` guard)
- Modify: `app/Models/User.php` (add `HasApiTokens`)
- Modify: `routes/ai.php` (register `Mcp::web()`)
- Create: migration (via `vendor:publish`)
- Test: `tests/Feature/Mcp/WebTransportAuthTest.php`

**Interfaces:**
- Produces: `$user->createToken($name)` usable by Task 11's controller.

- [ ] **Step 1: Install Sanctum**

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider" --tag="sanctum-migrations"
```

This creates a new migration file under `database/migrations/` for the `personal_access_tokens` table (standard Sanctum schema — do not hand-edit it).

- [ ] **Step 2: Register the `sanctum` guard**

In `config/auth.php`, inside the `'guards'` array (after the existing `'web'` entry), add:

```php
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
```

- [ ] **Step 3: Add `HasApiTokens` to the User model**

In `app/Models/User.php`, add the import `use Laravel\Sanctum\HasApiTokens;` and add `HasApiTokens` to the existing `use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;` trait list (becomes `use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;`).

- [ ] **Step 4: Run the migration**

```bash
php artisan migrate
```

- [ ] **Step 5: Write the failing test**

Create `tests/Feature/Mcp/WebTransportAuthTest.php`:

```php
<?php

use App\Models\User;

test('the web MCP endpoint rejects requests without a token', function () {
    $response = $this->postJson('/mcp/biglins', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ], ['Accept' => 'application/json, text/event-stream']);

    $response->assertUnauthorized();
});

test('the web MCP endpoint accepts a valid Sanctum token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('agent')->plainTextToken;

    $response = $this->postJson('/mcp/biglins', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ], [
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertOk();
});
```

- [ ] **Step 6: Run the test to verify it fails**

Run: `php artisan test --compact --filter=WebTransportAuthTest`
Expected: FAIL — 404 (no `/mcp/biglins` route registered yet)

- [ ] **Step 7: Register the web transport**

Replace the contents of `routes/ai.php`:

```php
<?php

use App\Mcp\Servers\BiglinsServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('biglins', BiglinsServer::class);

Mcp::web('/mcp/biglins', BiglinsServer::class)->middleware('auth:sanctum');
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test --compact --filter=WebTransportAuthTest`
Expected: PASS

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock config/auth.php app/Models/User.php routes/ai.php database/migrations tests/Feature/Mcp/WebTransportAuthTest.php
git commit -m "feat: add Sanctum-authenticated web transport for the MCP server"
```

---

### Task 11: Settings > API Tokens — backend

**Files:**
- Create: `app/Http/Controllers/Settings/ApiTokenController.php`
- Create: `app/Http/Requests/Settings/StoreApiTokenRequest.php`
- Modify: `routes/settings.php`
- Test: `tests/Feature/Settings/ApiTokensTest.php`

**Interfaces:**
- Produces: routes `api-tokens.index` (GET), `api-tokens.store` (POST), `api-tokens.destroy` (DELETE) — consumed by Task 12's Vue page.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Settings/ApiTokensTest.php`:

```php
<?php

use App\Models\User;

test('guests are redirected to the login page when visiting api tokens settings', function () {
    $this->get(route('api-tokens.index'))->assertRedirect(route('login'));
});

test('api tokens settings page lists the user\'s tokens', function () {
    $user = User::factory()->create();
    $user->createToken('laptop');

    $response = $this->actingAs($user)->get(route('api-tokens.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('settings/ApiTokens')
        ->has('tokens', 1)
        ->where('tokens.0.name', 'laptop'));
});

test('a token can be created and the plaintext value is returned once', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('api-tokens.store'), [
        'name' => 'agent-1',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('api-tokens.index'));
    expect($user->fresh()->tokens)->toHaveCount(1);
    expect($user->fresh()->tokens->first()->name)->toBe('agent-1');
});

test('a token name is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('api-tokens.store'), ['name' => '']);

    $response->assertSessionHasErrors('name');
});

test('a token can be revoked', function () {
    $user = User::factory()->create();
    $token = $user->createToken('laptop');

    $response = $this->actingAs($user)->delete(route('api-tokens.destroy', $token->accessToken->id));

    $response->assertRedirect(route('api-tokens.index'));
    expect($user->fresh()->tokens)->toHaveCount(0);
});

test('a user cannot revoke another user\'s token', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $token = $otherUser->createToken('laptop');

    $response = $this->actingAs($user)->delete(route('api-tokens.destroy', $token->accessToken->id));

    $response->assertForbidden();
    expect($otherUser->fresh()->tokens)->toHaveCount(1);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=ApiTokensTest`
Expected: FAIL — `route('api-tokens.index')` undefined

- [ ] **Step 3: Write the form request**

Create `app/Http/Requests/Settings/StoreApiTokenRequest.php`:

```php
<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreApiTokenRequest extends FormRequest
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
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/Settings/ApiTokenController.php`:

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreApiTokenRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/ApiTokens', [
            'tokens' => request()->user()
                ->tokens()
                ->select(['id', 'name', 'created_at', 'last_used_at'])
                ->latest()
                ->get()
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at_diff' => $token->created_at?->diffForHumans(),
                    'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreApiTokenRequest $request): RedirectResponse
    {
        $token = $request->user()->createToken($request->validated('name'));

        Inertia::flash('newApiToken', $token->plainTextToken);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token created.')]);

        return to_route('api-tokens.index');
    }

    public function destroy(int $token): RedirectResponse
    {
        $accessToken = PersonalAccessToken::query()->findOrFail($token);

        abort_unless($accessToken->tokenable_id === request()->user()->id, 403);

        $accessToken->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token revoked.')]);

        return to_route('api-tokens.index');
    }
}
```

- [ ] **Step 5: Register the routes**

In `routes/settings.php`, add the import `use App\Http\Controllers\Settings\ApiTokenController;`, then inside the existing `Route::middleware(['auth', 'verified'])->group(function () { ... })` block (after the `security.edit` route), add:

```php
    Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=ApiTokensTest`
Expected: PASS

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Settings/ApiTokenController.php app/Http/Requests/Settings/StoreApiTokenRequest.php routes/settings.php tests/Feature/Settings/ApiTokensTest.php
git commit -m "feat: add Settings > API Tokens backend"
```

---

### Task 12: Settings > API Tokens — frontend

**Files:**
- Create: `resources/js/pages/settings/ApiTokens.vue`
- Modify: `resources/js/layouts/settings/Layout.vue` (nav entry)
- Modify: `resources/js/lang/en.ts`, `resources/js/lang/it.ts`, `resources/js/lang/es.ts` (translation keys)

**Interfaces:**
- Consumes: `tokens` prop shape `{id: number, name: string, created_at_diff: string|null, last_used_at_diff: string|null}[]` and flash keys `newApiToken`/`toast` from Task 11's controller; routes `api-tokens.index`/`api-tokens.store`/`api-tokens.destroy` generated by Wayfinder from those same routes.

- [ ] **Step 1: Generate Wayfinder actions for the new routes**

```bash
php artisan wayfinder:generate
```

This regenerates `resources/js/actions/App/Http/Controllers/Settings/ApiTokenController.ts` and `resources/js/routes/api-tokens/index.ts` (or equivalent, following the existing `routes/security.ts`/`actions/.../SecurityController.ts` naming already in the project) — inspect the generated files after running this command rather than guessing their exact export names, since Wayfinder's output layout is derived mechanically from `routes/settings.php`.

- [ ] **Step 2: Add translation keys**

In `resources/js/lang/en.ts`, inside the `settings` object, add a `nav.apiTokens` entry alongside the existing `nav` keys and a new `apiTokens` section alongside `language`/`profile`:

```ts
        nav: {
            profile: 'Profile',
            security: 'Security',
            appearance: 'Appearance',
            language: 'Language',
            apiTokens: 'API Tokens',
        },
```

```ts
        apiTokens: {
            title: 'API tokens',
            pageTitle: 'API token settings',
            description:
                'Create tokens to let AI agents connect to this Biglins instance over the MCP server.',
            namePlaceholder: 'Token name (e.g. "agent-1")',
            create: 'Create token',
            empty: 'No API tokens yet.',
            createdAt: 'Created {time}',
            lastUsedAt: 'Last used {time}',
            neverUsed: 'Never used',
            revoke: 'Revoke',
            confirmRevoke: 'Revoke this token? Any agent using it will lose access immediately.',
            newTokenTitle: 'Copy your new token now',
            newTokenDescription:
                "This is the only time it will be shown. Store it somewhere safe — it won't be recoverable afterwards.",
            copy: 'Copy',
            copied: 'Copied!',
        },
```

Mirror the same two additions in `resources/js/lang/it.ts`:

```ts
        nav: {
            profile: 'Profilo',
            security: 'Sicurezza',
            appearance: 'Aspetto',
            language: 'Lingua',
            apiTokens: 'Token API',
        },
```

```ts
        apiTokens: {
            title: 'Token API',
            pageTitle: 'Impostazioni token API',
            description:
                'Crea token per permettere agli agenti AI di collegarsi a questa istanza di Biglins tramite il server MCP.',
            namePlaceholder: 'Nome del token (es. "agente-1")',
            create: 'Crea token',
            empty: 'Nessun token API.',
            createdAt: 'Creato {time}',
            lastUsedAt: 'Ultimo utilizzo {time}',
            neverUsed: 'Mai utilizzato',
            revoke: 'Revoca',
            confirmRevoke: 'Revocare questo token? Ogni agente che lo usa perderà l\'accesso immediatamente.',
            newTokenTitle: 'Copia subito il tuo nuovo token',
            newTokenDescription:
                'Verrà mostrato solo questa volta. Conservalo in un posto sicuro: non potrà essere recuperato in seguito.',
            copy: 'Copia',
            copied: 'Copiato!',
        },
```

And in `resources/js/lang/es.ts`:

```ts
        nav: {
            profile: 'Perfil',
            security: 'Seguridad',
            appearance: 'Apariencia',
            language: 'Idioma',
            apiTokens: 'Tokens API',
        },
```

```ts
        apiTokens: {
            title: 'Tokens API',
            pageTitle: 'Ajustes de tokens API',
            description:
                'Crea tokens para permitir que los agentes de IA se conecten a esta instancia de Biglins a través del servidor MCP.',
            namePlaceholder: 'Nombre del token (ej. "agente-1")',
            create: 'Crear token',
            empty: 'No hay tokens API todavía.',
            createdAt: 'Creado {time}',
            lastUsedAt: 'Último uso {time}',
            neverUsed: 'Nunca usado',
            revoke: 'Revocar',
            confirmRevoke: '¿Revocar este token? Cualquier agente que lo use perderá el acceso de inmediato.',
            newTokenTitle: 'Copia tu nuevo token ahora',
            newTokenDescription:
                'Se mostrará solo esta vez. Guárdalo en un lugar seguro: no podrá recuperarse después.',
            copy: 'Copiar',
            copied: '¡Copiado!',
        },
```

(Use each file's existing indentation/quote style — read the surrounding `settings` block before editing to match it exactly.)

- [ ] **Step 3: Add the sidebar nav entry**

In `resources/js/layouts/settings/Layout.vue`, add the import `import { index as editApiTokens } from '@/routes/api-tokens';` (adjust the import path to whatever Wayfinder generated in Step 1) and append to `sidebarNavItems`:

```ts
    {
        title: t('settings.nav.apiTokens'),
        href: editApiTokens(),
    },
```

- [ ] **Step 4: Write the page**

Create `resources/js/pages/settings/ApiTokens.vue`:

```vue
<script setup lang="ts">
import { router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ApiTokenController from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/api-tokens';
import type { BreadcrumbItem } from '@/types';

type ApiToken = {
    id: number;
    name: string;
    created_at_diff: string | null;
    last_used_at_diff: string | null;
};

const props = defineProps<{ tokens: ApiToken[] }>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('settings.apiTokens.pageTitle'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const name = ref('');
const nameError = ref<string | undefined>(undefined);
const creating = ref(false);
const copied = ref(false);

const page = usePage<{ props: { newApiToken?: string } }>();
const newToken = ref(page.props.newApiToken as string | undefined);

function createToken(): void {
    creating.value = true;
    nameError.value = undefined;

    router.post(
        ApiTokenController.store.url(),
        { name: name.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                name.value = '';
                newToken.value = usePage().props.newApiToken as
                    | string
                    | undefined;
            },
            onError: (errors) => {
                nameError.value = errors.name;
            },
            onFinish: () => {
                creating.value = false;
            },
        },
    );
}

function revokeToken(tokenId: number): void {
    if (!confirm(t('settings.apiTokens.confirmRevoke'))) {
        return;
    }

    router.delete(ApiTokenController.destroy.url(tokenId), {
        preserveScroll: true,
    });
}

async function copyToken(): Promise<void> {
    if (!newToken.value) {
        return;
    }

    await navigator.clipboard.writeText(newToken.value);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
}
</script>

<template>
    <h1 class="sr-only">{{ t('settings.apiTokens.pageTitle') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('settings.apiTokens.title')"
            :description="t('settings.apiTokens.description')"
        />

        <div
            v-if="newToken"
            class="space-y-2 rounded-md border border-amber-400 bg-amber-50 p-4 dark:bg-amber-950"
        >
            <p class="font-medium">
                {{ t('settings.apiTokens.newTokenTitle') }}
            </p>
            <p class="text-muted-foreground text-sm">
                {{ t('settings.apiTokens.newTokenDescription') }}
            </p>
            <div class="flex items-center gap-2">
                <code
                    class="bg-muted flex-1 overflow-x-auto rounded p-2 text-sm"
                    >{{ newToken }}</code
                >
                <Button variant="outline" size="sm" @click="copyToken">
                    {{
                        copied
                            ? t('settings.apiTokens.copied')
                            : t('settings.apiTokens.copy')
                    }}
                </Button>
            </div>
        </div>

        <form
            class="flex items-end gap-4"
            @submit.prevent="createToken"
        >
            <div class="grid flex-1 gap-2">
                <Label for="token-name">{{
                    t('settings.apiTokens.namePlaceholder')
                }}</Label>
                <Input id="token-name" v-model="name" required />
                <InputError :message="nameError" />
            </div>
            <Button :disabled="creating" type="submit">
                {{ t('settings.apiTokens.create') }}
            </Button>
        </form>

        <p
            v-if="props.tokens.length === 0"
            class="text-muted-foreground text-sm"
        >
            {{ t('settings.apiTokens.empty') }}
        </p>

        <ul v-else class="divide-y">
            <li
                v-for="token in props.tokens"
                :key="token.id"
                class="flex items-center justify-between py-3"
            >
                <div>
                    <p class="font-medium">{{ token.name }}</p>
                    <p class="text-muted-foreground text-sm">
                        {{
                            t('settings.apiTokens.createdAt', {
                                time: token.created_at_diff,
                            })
                        }}
                        ·
                        {{
                            token.last_used_at_diff
                                ? t('settings.apiTokens.lastUsedAt', {
                                      time: token.last_used_at_diff,
                                  })
                                : t('settings.apiTokens.neverUsed')
                        }}
                    </p>
                </div>
                <Button
                    variant="destructive"
                    size="sm"
                    @click="revokeToken(token.id)"
                >
                    {{ t('settings.apiTokens.revoke') }}
                </Button>
            </li>
        </ul>
    </div>
</template>
```

Before finalizing, check `resources/js/components/ui/input` exists (it's used by other settings forms, e.g. `Profile.vue`) and that `Inertia::flash()` values surface on `page.props` the same way `toast` already does elsewhere in the app (see `resources/js/lib/flashToast.ts`) — follow that existing mechanism rather than introducing a new one if it differs from the `usePage().props.newApiToken` access used above.

- [ ] **Step 5: Manual verification**

Start the dev server (`composer run dev` or ask the user to, per project convention) and, logged in:
1. Visit Settings — confirm "API Tokens" appears in the sidebar nav.
2. Create a token — confirm the plaintext value is shown once, the list updates, and reloading the page no longer shows the plaintext value.
3. Revoke the token — confirm it disappears from the list.

- [ ] **Step 6: Commit**

```bash
git add resources/js
git commit -m "feat: add Settings > API Tokens page"
```

---

## Self-Review Notes

- **Spec coverage:** §1 (dual transport) → Tasks 2, 10. §2 (Sanctum auth + token UI) → Tasks 10, 11, 12. §3 (`CurrentCompany::runningAs`) → Task 1, consumed by Tasks 3, 5, 7. §4 (all 8 tools) → Tasks 2–9. §5 (testing) → a test file per task; the web-transport 401/200 smoke test is Task 10's `WebTransportAuthTest`.
- **Type consistency:** every write tool's `handle()` follows the same `validate company_id → findOrFail → runningAs → validate business rules → Response::structured` shape; every read/list tool follows the same `validate company_id → query → Response::structured` shape. `CurrentCompany::runningAs(Company $company, Closure $callback): mixed` (Task 1) is the exact signature every later task calls.
