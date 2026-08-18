<?php

use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\ListCustomersTool;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Support\Str;

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
        'company_id' => (string) Str::uuid(),
    ]);

    $response->assertHasErrors();
});
