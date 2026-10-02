<?php

use App\EInvoicing\Compliance\DefaultComplianceRules;
use App\EInvoicing\Compliance\ItalyComplianceRules;
use App\EInvoicing\Compliance\SpainComplianceRules;
use App\EInvoicing\CountryComplianceResolver;
use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;

function italianCompany(): Company
{
    return Company::factory()->create([
        'country_id' => Country::factory()->italy(),
        'vat_number' => '01234567890',
        'address' => 'Via Roma 1', 'zip' => '00100', 'city' => 'Roma', 'province' => 'RM',
        'fiscal_details' => ['tax_regime' => 'RF01'],
    ]);
}

function spanishCompany(): Company
{
    return Company::factory()->create([
        'country_id' => Country::factory()->spain(),
        'vat_number' => 'B12345678',
    ]);
}

function invoiceFor(Company $company, array $customerAttributes, array $rowAttributes = []): Invoice
{
    $customer = Customer::factory()->create(['company_id' => $company->id, ...$customerAttributes]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id]);
    InvoiceRow::factory()->for($invoice)->create(['vat_rate' => 22, 'vat_exemption_code' => null, ...$rowAttributes]);

    return $invoice->load(['company.country', 'customer.country', 'rows']);
}

test('resolver picks rules by iso code and falls back to default', function () {
    expect(get_class(CountryComplianceResolver::forIsoCode('IT')))->toBe(ItalyComplianceRules::class);
    expect(get_class(CountryComplianceResolver::forIsoCode('ES')))->toBe(SpainComplianceRules::class);
    expect(get_class(CountryComplianceResolver::forIsoCode('FR')))->toBe(DefaultComplianceRules::class);
    expect(get_class(CountryComplianceResolver::forIsoCode(null)))->toBe(DefaultComplianceRules::class);
    expect(get_class(CountryComplianceResolver::forCompany(Company::factory()->create(['country_id' => null]))))
        ->toBe(DefaultComplianceRules::class);
    expect(get_class(CountryComplianceResolver::forCompany(italianCompany())))->toBe(ItalyComplianceRules::class);
    expect(get_class(CountryComplianceResolver::forCountryId(Country::factory()->spain()->create()->id)))->toBe(SpainComplianceRules::class);
});

test('submission status groups', function (SubmissionStatus $status, bool $awaiting) {
    expect($status->isAwaitingAuthority())->toBe($awaiting);
    expect($status->isFinal())->toBe(! $awaiting);
})->with([
    [SubmissionStatus::Pending, true],
    [SubmissionStatus::Submitted, true],
    [SubmissionStatus::Failed, false],
    [SubmissionStatus::Rejected, false],
    [SubmissionStatus::Accepted, false],
    [SubmissionStatus::Delivered, false],
    [SubmissionStatus::NotDelivered, false],
]);

test('italian company address fields are required', function (string $field) {
    $company = italianCompany();
    $company->update([$field => null]);

    $invoice = invoiceFor($company, ['country_id' => $company->country_id, 'vat_number' => '09876543210', 'fiscal_details' => ['recipient_code' => 'ABC1234']]);

    expect(array_keys((new ItalyComplianceRules)->validateForIssue($invoice)))->toBe(["company.{$field}"]);
})->with(['address', 'zip', 'city']);

test('submission and revision policy per country', function () {
    expect((new DefaultComplianceRules)->requiresSubmission())->toBeFalse();
    expect((new ItalyComplianceRules)->requiresSubmission())->toBeTrue();
    expect((new SpainComplianceRules)->requiresSubmission())->toBeTrue();

    expect((new ItalyComplianceRules)->allowsRevisionAfter(SubmissionStatus::Failed))->toBeTrue();
    expect((new ItalyComplianceRules)->allowsRevisionAfter(SubmissionStatus::Rejected))->toBeTrue();
    expect((new ItalyComplianceRules)->allowsRevisionAfter(SubmissionStatus::Delivered))->toBeFalse();
    expect((new SpainComplianceRules)->allowsRevisionAfter(SubmissionStatus::Failed))->toBeTrue();
    expect((new SpainComplianceRules)->allowsRevisionAfter(SubmissionStatus::Rejected))->toBeFalse();
});

test('vat exemption codes per country', function () {
    expect((new ItalyComplianceRules)->vatExemptionCodes())->toContain('N2.1', 'N3.1', 'N7')->not->toContain('E1');
    expect((new SpainComplianceRules)->vatExemptionCodes())->toContain('E1', 'E6', 'N1', 'N2')->not->toContain('N2.1');
    expect((new DefaultComplianceRules)->vatExemptionCodes())->toBe([]);
});

test('italian invoices validate', function (array $customer, array $row, array $expectedErrorKeys) {
    $company = italianCompany();
    $customer['country_id'] = Country::query()->where('iso_code', $customer['country'])->value('id')
        ?? Country::factory()->create(['iso_code' => $customer['country']])->id;
    unset($customer['country']);

    $errors = (new ItalyComplianceRules)->validateForIssue(invoiceFor($company, $customer, $row));

    expect(array_keys($errors))->toEqualCanonicalizing($expectedErrorKeys);
})->with([
    'b2b ok with recipient code' => [['country' => 'IT', 'vat_number' => '09876543210', 'fiscal_details' => ['recipient_code' => 'ABC1234']], [], []],
    'b2b ok with pec' => [['country' => 'IT', 'vat_number' => '09876543210', 'fiscal_details' => ['pec' => 'x@pec.it']], [], []],
    'b2b missing recipient' => [['country' => 'IT', 'vat_number' => '09876543210', 'fiscal_details' => null], [], ['customer.fiscal_details.recipient_code']],
    'b2c needs tax code' => [['country' => 'IT', 'vat_number' => null, 'tax_code' => null], [], ['customer.tax_code']],
    'b2c ok' => [['country' => 'IT', 'vat_number' => null, 'tax_code' => 'RSSMRA80A01H501U'], [], []],
    'foreign needs vat number' => [['country' => 'DE', 'vat_number' => null], [], ['customer.vat_number']],
    'foreign ok' => [['country' => 'DE', 'vat_number' => 'DE123456789'], [], []],
    'zero rate needs natura' => [['country' => 'DE', 'vat_number' => 'DE123456789'], ['vat_rate' => 0], ['rows.0.vat_exemption_code']],
    'zero rate with natura' => [['country' => 'DE', 'vat_number' => 'DE123456789'], ['vat_rate' => 0, 'vat_exemption_code' => 'N2.1'], []],
    'zero rate with spanish code' => [['country' => 'DE', 'vat_number' => 'DE123456789'], ['vat_rate' => 0, 'vat_exemption_code' => 'E1'], ['rows.0.vat_exemption_code']],
]);

test('italian company data is required', function () {
    $company = italianCompany();
    $company->update(['vat_number' => null, 'province' => null, 'fiscal_details' => null]);

    $invoice = invoiceFor($company, ['country_id' => $company->country_id, 'vat_number' => '09876543210', 'fiscal_details' => ['recipient_code' => 'ABC1234']]);

    expect(array_keys((new ItalyComplianceRules)->validateForIssue($invoice)))
        ->toEqualCanonicalizing(['company.vat_number', 'company.province', 'company.fiscal_details.tax_regime']);
});

test('spanish invoices validate', function (array $customer, array $row, array $expectedErrorKeys) {
    $company = spanishCompany();
    $customer['country_id'] = Country::query()->where('iso_code', $customer['country'])->value('id')
        ?? Country::factory()->create(['iso_code' => $customer['country']])->id;
    unset($customer['country']);

    $errors = (new SpainComplianceRules)->validateForIssue(invoiceFor($company, $customer, $row));

    expect(array_keys($errors))->toEqualCanonicalizing($expectedErrorKeys);
})->with([
    'domestic ok' => [['country' => 'ES', 'vat_number' => 'B87654321'], [], []],
    'domestic missing nif' => [['country' => 'ES', 'vat_number' => null], [], ['customer.vat_number']],
    'foreign needs id type' => [['country' => 'FR', 'vat_number' => 'FR12345678901', 'fiscal_details' => null], [], ['customer.fiscal_details.id_type']],
    'foreign ok' => [['country' => 'FR', 'vat_number' => 'FR12345678901', 'fiscal_details' => ['id_type' => '02']], [], []],
    'zero rate needs cause' => [['country' => 'ES', 'vat_number' => 'B87654321'], ['vat_rate' => 0], ['rows.0.vat_exemption_code']],
    'zero rate with cause' => [['country' => 'ES', 'vat_number' => 'B87654321'], ['vat_rate' => 0, 'vat_exemption_code' => 'E5'], []],
]);

test('spanish company needs a nif', function () {
    $company = spanishCompany();
    $company->update(['vat_number' => null]);

    $invoice = invoiceFor($company, ['country_id' => $company->country_id, 'vat_number' => 'B87654321']);

    expect(array_keys((new SpainComplianceRules)->validateForIssue($invoice)))->toBe(['company.vat_number']);
});

test('default rules only require at least one row', function () {
    $invoice = Invoice::factory()->create();

    expect(array_keys((new DefaultComplianceRules)->validateForIssue($invoice->load('rows'))))->toBe(['rows']);
});
