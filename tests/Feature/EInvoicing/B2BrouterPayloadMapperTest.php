<?php

use App\EInvoicing\Providers\B2Brouter\InvoicePayloadMapper;
use App\Enums\InvoiceType;
use App\Models\Company;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;

function mappedInvoice(string $companyIso, array $customer, array $rows, bool $creditNote = false): array
{
    $company = Company::factory()->create(['country_id' => Country::factory()->create(['iso_code' => $companyIso]), 'vat_number' => 'X1']);
    $customerCountry = Country::query()->where('iso_code', $customer['iso'])->first() ?? Country::factory()->create(['iso_code' => $customer['iso']]);
    unset($customer['iso']);
    $invoice = Invoice::factory()->issued()->create([
        'company_id' => $company->id,
        'customer_id' => Customer::factory()->create(['company_id' => $company->id, 'country_id' => $customerCountry->id, 'zip' => null, 'fiscal_details' => null, ...$customer])->id,
        'number' => '2026-0001',
        'invoice_date' => '2026-10-02',
        'language' => 'it',
        'type' => $creditNote ? InvoiceType::CreditNote : InvoiceType::Invoice,
    ]);

    foreach ($rows as $row) {
        InvoiceRow::factory()->for($invoice)->create(['vat_exemption_code' => null, ...$row]);
    }

    return (new InvoicePayloadMapper)->map($invoice->fresh(['company.country', 'customer.country', 'rows']));
}

test('italian b2b invoice', function () {
    $payload = mappedInvoice('IT', ['iso' => 'IT', 'vat_number' => '09876543210', 'zip' => '00100', 'fiscal_details' => ['recipient_code' => 'abc1234']], [
        ['description' => 'Consulting', 'quantity' => 2, 'price' => 100, 'vat_rate' => 22],
    ]);

    expect($payload)->toMatchArray(['type' => 'IssuedInvoice', 'number' => '2026-0001', 'date' => '2026-10-02', 'currency' => 'EUR']);
    expect($payload['contact'])->toMatchArray([
        'tin_value' => '09876543210', 'tin_scheme' => '9906', 'country' => 'it', 'postalcode' => '00100',
        'transport_type_code' => 'it.sdi', 'document_type_code' => 'xml.fatturapa.1.2', 'recipient_code' => 'ABC1234',
    ]);
    expect($payload['invoice_lines_attributes'][0])->toMatchArray([
        'description' => 'Consulting', 'quantity' => 2.0, 'price' => 100.0,
        'taxes_attributes' => [['name' => 'IVA', 'category' => 'S', 'percent' => 22.0]],
    ]);
});

test('italian private customer uses the tax code and 0000000', function () {
    $payload = mappedInvoice('IT', ['iso' => 'IT', 'vat_number' => null, 'tax_code' => 'RSSMRA80A01H501U'], [['vat_rate' => 22]]);

    expect($payload['contact'])->toMatchArray(['cin_value' => 'RSSMRA80A01H501U', 'cin_scheme' => '9907', 'recipient_code' => '0000000']);
    expect($payload['contact'])->not->toHaveKey('tin_value');
});

test('italian foreign customer uses XXXXXXX, 00000 and a natura code', function () {
    $payload = mappedInvoice('IT', ['iso' => 'DE', 'vat_number' => '123456789'], [['vat_rate' => 0, 'vat_exemption_code' => 'N3.2']]);

    expect($payload['contact'])->toMatchArray(['recipient_code' => 'XXXXXXX', 'postalcode' => '00000', 'tin_value' => 'DE123456789', 'country' => 'de']);
    expect($payload['contact'])->not->toHaveKey('tin_scheme');
    expect($payload['invoice_lines_attributes'][0]['taxes_attributes'][0])->toBe(['name' => 'IVA', 'category' => 'N3.2', 'percent' => 0.0]);
});

test('italian credit note uses positive amounts and the pec', function () {
    $payload = mappedInvoice('IT', ['iso' => 'IT', 'vat_number' => '09876543210', 'fiscal_details' => ['pec' => 'x@pec.it']], [['price' => -50, 'quantity' => 1, 'vat_rate' => 22]], creditNote: true);

    expect($payload['is_credit_note'])->toBeTrue();
    expect($payload['invoice_lines_attributes'][0]['price'])->toBe(50.0);
    expect($payload['contact']['certified_email'])->toBe('x@pec.it');
    expect($payload['contact']['recipient_code'])->toBe('0000000');
});

test('spanish invoice with exemption causes', function () {
    $payload = mappedInvoice('ES', ['iso' => 'ES', 'vat_number' => 'B87654321'], [
        ['vat_rate' => 0, 'vat_exemption_code' => 'E5'],
        ['vat_rate' => 0, 'vat_exemption_code' => 'N2'],
        ['vat_rate' => 21],
    ]);

    expect($payload['contact'])->toMatchArray(['tin_value' => 'ESB87654321', 'tin_scheme' => '9920', 'country' => 'es']);
    expect($payload['contact'])->not->toHaveKey('transport_type_code');
    expect(array_column($payload['invoice_lines_attributes'], 'taxes_attributes'))->toBe([
        [['name' => 'IVA', 'category' => 'E', 'percent' => 0.0, 'comment' => 'E5']],
        [['name' => 'IVA', 'category' => 'NS', 'percent' => 0.0, 'comment' => 'N2']],
        [['name' => 'IVA', 'category' => 'S', 'percent' => 21.0]],
    ]);
});

test('spanish credit note keeps negative amounts without the credit note flag', function () {
    $payload = mappedInvoice('ES', ['iso' => 'ES', 'vat_number' => 'B87654321'], [['price' => -50, 'quantity' => 1, 'vat_rate' => 21]], creditNote: true);

    expect($payload)->not->toHaveKey('is_credit_note');
    expect($payload['invoice_lines_attributes'][0]['price'])->toBe(-50.0);
});
