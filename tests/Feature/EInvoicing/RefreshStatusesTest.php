<?php

use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\EInvoicing\Providers\FakeProvider;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use App\Models\User;

test('the command refreshes only submissions waiting for more than an hour', function () {
    $old = InvoiceSubmission::factory()->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()->subHours(2)]);
    $recent = InvoiceSubmission::factory()->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()->subMinutes(10)]);
    $final = InvoiceSubmission::factory()->create(['status' => SubmissionStatus::Accepted, 'submitted_at' => now()->subHours(2)]);
    FakeProvider::queueStatusResult(new SubmissionResult(SubmissionStatus::Accepted));

    $this->artisan('einvoicing:refresh-statuses')->assertSuccessful();

    expect($old->fresh()->status)->toBe(SubmissionStatus::Accepted);
    expect($recent->fresh()->status)->toBe(SubmissionStatus::Submitted);
    expect($final->fresh()->status)->toBe(SubmissionStatus::Accepted);
});

test('one failing submission does not stop the command', function () {
    InvoiceSubmission::factory()->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()->subHours(3)]);
    $second = InvoiceSubmission::factory()->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()->subHours(2)]);
    FakeProvider::queueStatusResult(new TransientProviderException('503'));
    FakeProvider::queueStatusResult(new SubmissionResult(SubmissionStatus::Accepted));

    $this->artisan('einvoicing:refresh-statuses')->assertSuccessful();

    expect($second->fresh()->status)->toBe(SubmissionStatus::Accepted);
});

test('the refresh route updates the latest submission', function () {
    $company = Company::factory()->create(['is_default' => true]);
    $invoice = Invoice::factory()->issued()->create(['company_id' => $company->id]);
    $submission = InvoiceSubmission::factory()->for($invoice)->create(['status' => SubmissionStatus::Submitted]);
    FakeProvider::queueStatusResult(new SubmissionResult(SubmissionStatus::Delivered));

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.refresh-status', $invoice))
        ->assertRedirect(route('invoices.edit', $invoice));

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Delivered);
});

test('the desktop build refreshes on open at most every five minutes', function () {
    config(['nativephp-internal.running' => true]);
    $company = Company::factory()->create(['is_default' => true]);
    $invoice = Invoice::factory()->issued()->create(['company_id' => $company->id]);
    $submission = InvoiceSubmission::factory()->for($invoice)->create(['status' => SubmissionStatus::Submitted]);
    FakeProvider::queueStatusResult(new SubmissionResult(SubmissionStatus::Submitted, 'processing'));
    FakeProvider::queueStatusResult(new SubmissionResult(SubmissionStatus::Accepted));
    $this->actingAs(User::factory()->create());

    $this->get(route('invoices.edit', $invoice))->assertOk();
    $this->get(route('invoices.edit', $invoice))->assertOk();

    expect($submission->fresh()->provider_status)->toBe('processing');
    expect($submission->fresh()->status)->toBe(SubmissionStatus::Submitted);
});
