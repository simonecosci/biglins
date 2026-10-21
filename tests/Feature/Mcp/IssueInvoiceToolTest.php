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
