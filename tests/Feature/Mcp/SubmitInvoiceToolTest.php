<?php

use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Providers\FakeProvider;
use App\Enums\InvoiceStatus;
use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\SubmitInvoiceTool;
use App\Models\Company;
use App\Models\Country;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceRow;

function issuedSpanishInvoice(bool $eInvoicingEnabled = true): Invoice
{
    $spain = Country::factory()->spain()->create();
    $company = Company::factory()->create(['country_id' => $spain->id, 'vat_number' => 'B12345678', 'einvoicing_enabled' => $eInvoicingEnabled]);
    EInvoicingIntegration::factory()->create(['company_id' => $company->id]);
    $invoice = Invoice::factory()->issued()->create(['company_id' => $company->id]);
    $invoice->customer->update(['vat_number' => 'B87654321', 'country_id' => $spain->id]);
    InvoiceRow::factory()->for($invoice)->create(['vat_rate' => 21]);

    return $invoice;
}

test('submit_invoice sends an issued invoice', function () {
    $invoice = issuedSpanishInvoice();

    BiglinsServer::tool(SubmitInvoiceTool::class, ['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id])
        ->assertOk()
        ->assertSee('submitted');

    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Submitted);
    expect(FakeProvider::$sentInvoiceIds)->toBe([$invoice->id]);
});

test('submit_invoice refuses companies without electronic invoicing', function () {
    $invoice = issuedSpanishInvoice(eInvoicingEnabled: false);

    BiglinsServer::tool(SubmitInvoiceTool::class, ['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id])
        ->assertHasErrors();

    expect($invoice->submissions()->count())->toBe(0);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
});

test('submit_invoice refuses invoices of another company', function () {
    $invoice = issuedSpanishInvoice();

    BiglinsServer::tool(SubmitInvoiceTool::class, ['company_id' => Company::factory()->create()->id, 'invoice_id' => $invoice->id])
        ->assertHasErrors();

    expect($invoice->submissions()->count())->toBe(0);
});
