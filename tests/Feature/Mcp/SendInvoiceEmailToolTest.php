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
