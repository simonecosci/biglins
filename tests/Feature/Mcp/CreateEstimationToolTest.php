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
