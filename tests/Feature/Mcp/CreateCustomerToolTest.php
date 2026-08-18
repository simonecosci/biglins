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
