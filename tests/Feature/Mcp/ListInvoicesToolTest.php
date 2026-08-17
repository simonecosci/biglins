<?php

use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\ListInvoicesTool;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Str;

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
        'company_id' => (string) Str::uuid(),
    ]);

    $response->assertHasErrors();
});
