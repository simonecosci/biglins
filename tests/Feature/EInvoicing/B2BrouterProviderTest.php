<?php

use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Exceptions\InvalidWebhookSignature;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\EInvoicing\Providers\B2Brouter\StatusMapper;
use App\EInvoicing\Providers\B2BrouterProvider;
use App\Models\Company;
use App\Models\Country;
use App\Models\Customer;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\InvoiceSubmission;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

function b2brouterFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/b2brouter/{$name}.json")), true);
}

function b2brouter(EInvoicingEnvironment $environment = EInvoicingEnvironment::Sandbox, array $credentials = ['api_key' => 'test_key', 'account_id' => '42', 'webhook_signing_secret' => 'whsec']): B2BrouterProvider
{
    return new B2BrouterProvider($credentials, $environment);
}

function spanishIssuedInvoice(): Invoice
{
    $spain = Country::factory()->spain()->create();
    $company = Company::factory()->create(['country_id' => $spain->id, 'vat_number' => 'B12345678']);
    $invoice = Invoice::factory()->issued()->create([
        'company_id' => $company->id,
        'customer_id' => Customer::factory()->create(['company_id' => $company->id, 'country_id' => $spain->id, 'vat_number' => 'B87654321'])->id,
    ]);
    InvoiceRow::factory()->for($invoice)->create(['vat_rate' => 21]);

    return $invoice->fresh(['company.country', 'customer.country', 'rows']);
}

function b2brouterIntegration(string $webhookSecret = 'local-secret'): EInvoicingIntegration
{
    $integration = EInvoicingIntegration::factory()->make(['driver' => EInvoicingDriver::B2Brouter]);
    $integration->webhook_secret = $webhookSecret;

    return $integration;
}

test('send creates the invoice with send_after_import and reads the qr', function () {
    Http::fake([
        'api.b2brouter.net/accounts/42/invoices' => Http::response(b2brouterFixture('invoice-created-es'), 201),
        'api.b2brouter.net/tax_reports/91' => Http::response(b2brouterFixture('tax-report-registered-es')),
    ]);

    $result = b2brouter()->send(spanishIssuedInvoice());

    expect($result->status)->toBe(SubmissionStatus::Accepted);
    expect($result->externalId)->toBe('4711');
    expect($result->qrCode)->toBe('iVBORw0KGgoAAAANSUhEUg==');
    expect($result->authorityId)->toStartWith('https://www2.agenciatributaria.gob.es');
    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.b2brouter.net/accounts/42/invoices'
        && $request->hasHeader('X-B2B-API-Key', 'test_key')
        && $request->hasHeader('X-B2B-API-Version', B2BrouterProvider::API_VERSION)
        && $request['send_after_import'] === true
        && $request['invoice']['number'] !== null);
});

test('staging uses the staging host', function () {
    Http::fake(['api-staging.b2brouter.net/*' => Http::response(b2brouterFixture('invoice-created-it'), 201)]);

    expect(b2brouter(EInvoicingEnvironment::Staging)->send(spanishIssuedInvoice())->status)->toBe(SubmissionStatus::Submitted);
});

test('client errors fail definitively with the provider message', function (string $fixtureName, int $status) {
    Http::fake(['*' => Http::response(b2brouterFixture($fixtureName), $status)]);

    $result = b2brouter()->send(spanishIssuedInvoice());

    expect($result->status)->toBe(SubmissionStatus::Failed);
    expect($result->errorMessage)->toBe(b2brouterFixture($fixtureName)['error']['message']);
})->with([['error-422', 422], ['error-401', 401], ['error-401', 403]]);

test('server errors, rate limits and timeouts are transient', function (string $case) {
    Http::fake(['*' => match ($case) {
        'server error' => Http::response('', 503),
        'rate limit' => Http::response('', 429),
        'timeout' => Http::failedConnection(),
    }]);

    expect(fn () => b2brouter()->send(spanishIssuedInvoice()))->toThrow(TransientProviderException::class);
})->with(['server error', 'rate limit', 'timeout']);

test('fetch status maps the latest tax report', function () {
    Http::fake([
        'api.b2brouter.net/invoices/5001' => Http::response(['invoice' => ['id' => 5001, 'state' => 'sent', 'tax_report_ids' => [92], 'to_net_id' => 'SDI-778']]),
        'api.b2brouter.net/tax_reports/92' => Http::response(b2brouterFixture('tax-report-deposited-it')),
    ]);
    $company = Company::factory()->create(['country_id' => Country::factory()->italy()]);
    $submission = InvoiceSubmission::factory()
        ->for(Invoice::factory()->issued()->create(['company_id' => $company->id]))
        ->create(['external_id' => '5001', 'driver' => EInvoicingDriver::B2Brouter]);

    $result = b2brouter()->fetchStatus($submission);

    expect($result->status)->toBe(SubmissionStatus::NotDelivered);
    expect($result->providerStatus)->toBe('deposited');
    expect($result->authorityId)->toBe('SDI-778');
});

test('status mapping', function (?string $invoiceState, ?string $taxReportState, string $isoCode, SubmissionStatus $expected) {
    expect(StatusMapper::map($invoiceState, $taxReportState, $isoCode))->toBe($expected);
})->with([
    ['sending', null, 'ES', SubmissionStatus::Submitted],
    ['error', null, 'IT', SubmissionStatus::Failed],
    ['sent', 'processing', 'ES', SubmissionStatus::Submitted],
    ['sent', 'registered', 'ES', SubmissionStatus::Accepted],
    ['sent', 'registered_with_errors', 'ES', SubmissionStatus::Accepted],
    ['sent', 'registered', 'IT', SubmissionStatus::Delivered],
    ['sent', 'deposited', 'IT', SubmissionStatus::NotDelivered],
    ['sent', 'refused', 'ES', SubmissionStatus::Rejected],
    ['error', 'error', 'IT', SubmissionStatus::Rejected],
]);

test('webhook hmac signature is verified', function () {
    $body = json_encode(['code' => 'tax_report.state_change', 'triggered_at' => 1732530071, 'data' => ['event_id' => 'e-1', 'state' => 'registered', 'object' => ['invoice_id' => 4711]]]);
    $timestamp = '1732530076';
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec');

    $valid = Request::create('/', 'POST', server: ['HTTP_X_B2BROUTER_SIGNATURE' => "t={$timestamp},s={$signature}"], content: $body);
    $notification = b2brouter()->parseWebhook($valid, b2brouterIntegration());

    expect($notification->eventId)->toBe('e-1');
    expect($notification->externalId)->toBe('4711');
    expect($notification->type)->toBe('tax_report.state_change');
    expect($notification->result)->toBeNull();

    $invalid = Request::create('/', 'POST', server: ['HTTP_X_B2BROUTER_SIGNATURE' => "t={$timestamp},s=deadbeef"], content: $body);
    expect(fn () => b2brouter()->parseWebhook($invalid, b2brouterIntegration()))->toThrow(InvalidWebhookSignature::class);
});

test('without a signing secret the integration secret in the query string is checked', function () {
    $provider = b2brouter(credentials: ['api_key' => 'k', 'account_id' => '42']);
    $body = json_encode(['code' => 'issued_invoice.state_change', 'data' => ['event_id' => 'e-2', 'invoice_id' => 9, 'state' => 'sent']]);

    expect($provider->parseWebhook(Request::create('/?secret=local-secret', 'POST', content: $body), b2brouterIntegration())->externalId)->toBe('9');
    expect(fn () => $provider->parseWebhook(Request::create('/?secret=nope', 'POST', content: $body), b2brouterIntegration()))
        ->toThrow(InvalidWebhookSignature::class);
});

test('irrelevant webhook events return null', function () {
    $provider = b2brouter(credentials: ['api_key' => 'k', 'account_id' => '42']);
    $request = Request::create('/?secret=local-secret', 'POST', content: json_encode(['code' => 'received_invoice.created', 'data' => ['event_id' => 'e-3']]));

    expect($provider->parseWebhook($request, b2brouterIntegration()))->toBeNull();
});

test('test connection checks the account', function () {
    Http::fake(['api.b2brouter.net/accounts/42' => Http::sequence()->push(['account' => ['id' => 42]])->push(b2brouterFixture('error-401'), 401)]);

    expect(b2brouter()->testConnection())->toBeTrue();
    expect(b2brouter()->testConnection())->toBeFalse();
});
