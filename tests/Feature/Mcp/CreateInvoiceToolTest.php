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
