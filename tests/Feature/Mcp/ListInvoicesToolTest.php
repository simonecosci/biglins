<?php

use App\EInvoicing\Enums\SubmissionStatus;
use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\ListInvoicesTool;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
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

test('list_invoices exposes status, issued_at and submission status with drafts first', function () {
    $company = Company::factory()->create();
    $issued = Invoice::factory()->issued()->create(['company_id' => $company->id, 'number' => 7]);
    InvoiceSubmission::factory()->create(['invoice_id' => $issued->id, 'status' => SubmissionStatus::Accepted]);
    $draft = Invoice::factory()->draft()->create(['company_id' => $company->id, 'number' => null]);

    $response = BiglinsServer::tool(ListInvoicesTool::class, ['company_id' => $company->id]);

    $response->assertOk()
        ->assertSee('"status":"draft"')
        ->assertSee('"status":"issued"')
        ->assertSee('"submission_status":"accepted"');
    $response->assertStructuredContent(fn ($json) => $json->where('invoices.0.id', $draft->id)->where('invoices.1.id', $issued->id)->etc());
});
