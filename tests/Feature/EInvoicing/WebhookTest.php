<?php

use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\EInvoicing\Providers\FakeProvider;
use App\Models\Company;
use App\Models\Country;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Support\Str;

beforeEach(function () {
    $company = Company::factory()->create(['country_id' => Country::factory()->spain()]);
    $this->integration = EInvoicingIntegration::factory()->create(['company_id' => $company->id]);
    $this->submission = InvoiceSubmission::factory()
        ->for(Invoice::factory()->issued()->create(['company_id' => $company->id]))
        ->create(['e_invoicing_integration_id' => $this->integration->id, 'external_id' => 'ext-1']);
});

function postWebhook(EInvoicingIntegration $integration, array $body, ?string $secret = null, string $driver = 'fake')
{
    return test()->postJson(
        route('einvoicing.webhook', ['driver' => $driver, 'integration' => $integration->id]),
        $body,
        ['X-Fake-Secret' => $secret ?? $integration->webhook_secret],
    );
}

test('a valid webhook updates the submission and stores the event', function () {
    postWebhook($this->integration, ['event_id' => 'evt-1', 'external_id' => 'ext-1', 'status' => 'accepted'])->assertOk();

    expect($this->submission->fresh()->status)->toBe(SubmissionStatus::Accepted);
    expect($this->submission->events()->count())->toBe(1);
});

test('an invalid signature is refused', function () {
    postWebhook($this->integration, ['event_id' => 'evt-1', 'external_id' => 'ext-1', 'status' => 'accepted'], 'wrong')->assertForbidden();

    expect($this->submission->fresh()->status)->toBe(SubmissionStatus::Submitted);
});

test('duplicate events are ignored', function () {
    postWebhook($this->integration, ['event_id' => 'evt-1', 'external_id' => 'ext-1', 'status' => 'rejected'])->assertOk();
    $this->submission->refresh()->update(['status' => SubmissionStatus::Submitted]);

    postWebhook($this->integration, ['event_id' => 'evt-1', 'external_id' => 'ext-1', 'status' => 'rejected'])->assertOk();

    expect($this->submission->fresh()->status)->toBe(SubmissionStatus::Submitted);
    expect($this->submission->events()->count())->toBe(1);
});

test('unknown integrations and driver mismatches return 404', function () {
    test()->postJson(route('einvoicing.webhook', ['driver' => 'fake', 'integration' => (string) Str::uuid()]), [])->assertNotFound();
    postWebhook($this->integration, [], null, 'b2brouter')->assertNotFound();
});

test('irrelevant notifications are acknowledged', function () {
    postWebhook($this->integration, ['ping' => true])->assertOk();
});

test('a submission of another integration cannot be updated', function () {
    $otherIntegration = EInvoicingIntegration::factory()->create();

    postWebhook($otherIntegration, ['event_id' => 'evt-9', 'external_id' => 'ext-1', 'status' => 'rejected'])->assertOk();

    expect($this->submission->fresh()->status)->toBe(SubmissionStatus::Submitted);
});

test('a transient status fetch failure stores no event and a retry succeeds', function () {
    FakeProvider::reset();
    FakeProvider::queueStatusResult(new TransientProviderException('503'));

    postWebhook($this->integration, ['event_id' => 'evt-1', 'external_id' => 'ext-1'])->assertStatus(503);

    expect($this->submission->events()->count())->toBe(0);

    FakeProvider::queueStatusResult(new SubmissionResult(SubmissionStatus::Accepted, 'accepted', 'ext-1'));

    postWebhook($this->integration, ['event_id' => 'evt-1', 'external_id' => 'ext-1'])->assertOk();

    expect($this->submission->fresh()->status)->toBe(SubmissionStatus::Accepted);
    expect($this->submission->events()->count())->toBe(1);
});

test('the same event id on different integrations is recorded for each', function () {
    $otherSubmission = InvoiceSubmission::factory()->create(['external_id' => 'ext-1']);
    $otherIntegration = EInvoicingIntegration::find($otherSubmission->e_invoicing_integration_id);

    postWebhook($this->integration, ['event_id' => 'evt-1', 'external_id' => 'ext-1', 'status' => 'accepted'])->assertOk();
    postWebhook($otherIntegration, ['event_id' => 'evt-1', 'external_id' => 'ext-1', 'status' => 'accepted'])->assertOk();

    expect($this->submission->events()->count())->toBe(1);
    expect($otherSubmission->events()->count())->toBe(1);
    expect($otherSubmission->fresh()->status)->toBe(SubmissionStatus::Accepted);
});
