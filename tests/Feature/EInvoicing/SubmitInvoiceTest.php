<?php

use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\EInvoicingProviderFactory;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\EInvoicing\Providers\FakeProvider;
use App\EInvoicing\SubmissionResultRecorder;
use App\Enums\InvoiceStatus;
use App\Jobs\SubmitInvoice;
use App\Models\Company;
use App\Models\Country;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;

function pendingSubmission(): InvoiceSubmission
{
    $company = Company::factory()->create(['country_id' => Country::factory()->italy()]);
    $invoice = Invoice::factory()->issued()->create(['company_id' => $company->id, 'number' => '2026-0003']);

    return InvoiceSubmission::factory()->for($invoice)->create(['status' => SubmissionStatus::Pending, 'external_id' => null, 'submitted_at' => null]);
}

test('a successful send marks the submission submitted', function () {
    $submission = pendingSubmission();

    SubmitInvoice::dispatchSync($submission);

    $submission->refresh();
    expect($submission->status)->toBe(SubmissionStatus::Submitted);
    expect($submission->external_id)->toStartWith('fake-');
    expect($submission->submitted_at)->not->toBeNull();
});

test('transient errors are retried with backoff', function () {
    FakeProvider::queueSendResult(new TransientProviderException('503'));
    $job = (new SubmitInvoice(pendingSubmission()))->withFakeQueueInteractions();

    $job->handle(app(EInvoicingProviderFactory::class), app(SubmissionResultRecorder::class));

    $job->assertReleased(delay: 60);
    expect($job->submission->fresh()->status)->toBe(SubmissionStatus::Pending);
});

test('transient errors on the sync queue fail the submission and keep the number', function () {
    FakeProvider::queueSendResult(new TransientProviderException('timeout'));
    $submission = pendingSubmission();

    SubmitInvoice::dispatchSync($submission);

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Failed);
    expect($submission->invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
    expect($submission->invoice->fresh()->number)->toBe('2026-0003');
});

test('definitive errors fail immediately with the provider message', function () {
    FakeProvider::queueSendResult(SubmissionResult::failed('401 Unauthorized'));
    $submission = pendingSubmission();

    SubmitInvoice::dispatchSync($submission);

    expect($submission->fresh()->error_message)->toBe('401 Unauthorized');
    expect($submission->invoice->fresh()->number)->toBe('2026-0003');
});

test('the job is unique per submission and skips non pending submissions', function () {
    $submission = pendingSubmission();
    $job = new SubmitInvoice($submission);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe($submission->id);

    $submission->update(['status' => SubmissionStatus::Submitted]);
    SubmitInvoice::dispatchSync($submission);
    expect(FakeProvider::$sentInvoiceIds)->toBe([]);
});

test('the second attempt is released with the second backoff', function () {
    FakeProvider::queueSendResult(new TransientProviderException('503'));
    $job = (new SubmitInvoice(pendingSubmission()))->withFakeQueueInteractions();
    $job->job->attempts = 2;

    $job->handle(app(EInvoicingProviderFactory::class), app(SubmissionResultRecorder::class));

    $job->assertReleased(delay: 300);
});

test('the last attempt fails the submission and returns the invoice to draft keeping the number', function () {
    FakeProvider::queueSendResult(new TransientProviderException('503'));
    $submission = pendingSubmission();
    $job = (new SubmitInvoice($submission))->withFakeQueueInteractions();
    $job->job->attempts = 3;

    $job->handle(app(EInvoicingProviderFactory::class), app(SubmissionResultRecorder::class));

    $job->assertNotReleased();
    expect($submission->fresh()->status)->toBe(SubmissionStatus::Failed);
    expect($submission->invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
    expect($submission->invoice->fresh()->number)->toBe('2026-0003');
});

test('the failed hook fails a pending submission', function () {
    $submission = pendingSubmission();

    (new SubmitInvoice($submission))->failed(new Exception('boom'));

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Failed);
    expect($submission->fresh()->error_message)->toBe('boom');
    expect($submission->invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});

test('a second job for the same submission cannot take the unique lock', function () {
    $submission = pendingSubmission();
    $lock = new UniqueLock(Cache::driver());

    expect($lock->acquire(new SubmitInvoice($submission)))->toBeTrue();
    expect($lock->acquire(new SubmitInvoice($submission)))->toBeFalse();
    expect($lock->acquire(new SubmitInvoice(InvoiceSubmission::factory()->for($submission->invoice)->create())))->toBeTrue();
});
