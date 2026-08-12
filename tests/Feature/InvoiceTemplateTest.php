<?php

use App\Models\Invoice;
use App\Models\InvoiceRow;
use Illuminate\Support\Facades\App;

function renderInvoiceTemplate(Invoice $invoice): string
{
    App::setLocale($invoice->language);

    return view('invoices.template', [
        'invoice' => $invoice->load(['customer.country', 'company.country', 'rows']),
    ])->render();
}

test('template renders company data, customer data and rows', function () {
    $invoice = Invoice::factory()->create(['language' => 'en', 'note' => null]);
    InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Consulting work',
        'price' => 100,
        'vat_rate' => 22,
    ]);

    $html = renderInvoiceTemplate($invoice);

    expect($html)->toContain('Invoice');
    expect($html)->toContain($invoice->number);
    expect($html)->toContain(e($invoice->company->name));
    expect($html)->toContain(e($invoice->customer->name));
    expect($html)->toContain('Consulting work');
});

test('template labels switch per invoice language', function () {
    $it = Invoice::factory()->create(['language' => 'it']);
    $es = Invoice::factory()->create(['language' => 'es']);
    InvoiceRow::factory()->create(['invoice_id' => $it->id]);
    InvoiceRow::factory()->create(['invoice_id' => $es->id]);

    expect(renderInvoiceTemplate($it))->toContain('Fattura');
    expect(renderInvoiceTemplate($es))->toContain('Factura');
});

test('template shows the note after the totals section, at the bottom', function () {
    $invoice = Invoice::factory()->create(['language' => 'en', 'note' => 'Thank you for your business']);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $html = renderInvoiceTemplate($invoice);

    $totalPosition = strpos($html, 'Total');
    $notePosition = strpos($html, 'Thank you for your business');

    expect($totalPosition)->not->toBeFalse();
    expect($notePosition)->not->toBeFalse();
    expect($notePosition)->toBeGreaterThan($totalPosition);
});

test('template omits the notes section when the invoice has no note', function () {
    $invoice = Invoice::factory()->create(['language' => 'en', 'note' => null]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $html = renderInvoiceTemplate($invoice);

    expect($html)->not->toContain('id="notes"');
});

test('template renders the quantity column for each row', function () {
    $invoice = Invoice::factory()->create(['language' => 'en']);
    InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Consulting',
        'quantity' => 2,
        'price' => 100,
        'vat_rate' => 22,
    ]);

    $html = renderInvoiceTemplate($invoice);

    expect($html)->toContain('Quantity');
    expect($html)->toContain('2.00');
    expect($html)->toContain('244.00');
});
