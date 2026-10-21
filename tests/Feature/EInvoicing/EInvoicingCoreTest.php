<?php

use App\EInvoicing\EInvoicingProviderFactory;
use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Providers\FakeProvider;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('integration credentials are encrypted at rest and hidden', function () {
    $integration = EInvoicingIntegration::factory()->create(['credentials' => ['api_key' => 'secret-key']]);

    expect(DB::table('e_invoicing_integrations')->value('credentials'))->not->toContain('secret-key');
    expect($integration->fresh()->credentials)->toBe(['api_key' => 'secret-key']);
    expect($integration->toArray())->not->toHaveKeys(['credentials', 'webhook_secret']);
    expect($integration->webhook_secret)->toHaveLength(40);
});

test('one integration per company', function () {
    $integration = EInvoicingIntegration::factory()->create();

    expect(fn () => EInvoicingIntegration::factory()->create(['company_id' => $integration->company_id]))
        ->toThrow(QueryException::class);
});

test('drivers expose availability and countries', function () {
    expect(EInvoicingDriver::B2Brouter->supportedCountries())->toBe(['IT', 'ES']);
    expect(EInvoicingDriver::Fake->isAvailable())->toBeTrue();
    expect(EInvoicingDriver::availableFor('IT'))->toContain(EInvoicingDriver::B2Brouter, EInvoicingDriver::Fake);
    expect(EInvoicingDriver::availableFor('FR'))->toBe([]);
    expect(array_column(EInvoicingDriver::B2Brouter->credentialFields(), 'name'))->toContain('api_key', 'account_id');
});

test('the fake driver is unavailable in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(EInvoicingDriver::Fake->isAvailable())->toBeFalse();
    expect(fn () => app(EInvoicingProviderFactory::class)->forIntegration(EInvoicingIntegration::factory()->make()))
        ->toThrow(InvalidArgumentException::class);
});

test('factory builds the configured provider', function () {
    $provider = app(EInvoicingProviderFactory::class)->forIntegration(EInvoicingIntegration::factory()->create());

    expect($provider)->toBeInstanceOf(FakeProvider::class);
});

test('invoice latest submission is the most recent one', function () {
    $invoice = Invoice::factory()->issued()->create();
    InvoiceSubmission::factory()->for($invoice)->create(['status' => SubmissionStatus::Failed, 'created_at' => now()->subMinute()]);
    $latest = InvoiceSubmission::factory()->for($invoice)->create(['status' => SubmissionStatus::Submitted]);

    expect($invoice->latestSubmission->id)->toBe($latest->id);
});

test('provider event ids are unique', function () {
    $submission = InvoiceSubmission::factory()->create();
    $submission->events()->create(['type' => 'x', 'provider_event_id' => 'evt-1', 'payload' => [], 'received_at' => now()]);

    expect(fn () => $submission->events()->create(['type' => 'x', 'provider_event_id' => 'evt-1', 'payload' => [], 'received_at' => now()]))
        ->toThrow(QueryException::class);
});
