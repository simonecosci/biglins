<?php

use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\SubmissionResultRecorder;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Support\Facades\Storage;

function submissionFor(string $isoCode, SubmissionStatus $status = SubmissionStatus::Submitted): InvoiceSubmission
{
    $company = Company::factory()->create(['country_id' => Country::factory()->create(['iso_code' => $isoCode])]);
    $invoice = Invoice::factory()->issued()->create(['company_id' => $company->id, 'number' => '2026-0007']);

    return InvoiceSubmission::factory()->for($invoice)->create(['status' => $status, 'qr_code' => null]);
}

test('final results complete the submission', function () {
    $submission = submissionFor('IT');

    app(SubmissionResultRecorder::class)->record($submission, new SubmissionResult(SubmissionStatus::Delivered, 'RC', authorityId: '12345'));

    $submission->refresh();
    expect($submission->status)->toBe(SubmissionStatus::Delivered);
    expect($submission->authority_id)->toBe('12345');
    expect($submission->completed_at)->not->toBeNull();
    expect($submission->invoice->status)->toBe(InvoiceStatus::Issued);
});

test('italian rejection returns the invoice to draft keeping the number', function () {
    $submission = submissionFor('IT');

    app(SubmissionResultRecorder::class)->record($submission, new SubmissionResult(SubmissionStatus::Rejected, 'NS', errorMessage: '00404'));

    $invoice = $submission->invoice->fresh();
    expect($invoice->status)->toBe(InvoiceStatus::Draft);
    expect($invoice->number)->toBe('2026-0007');
});

test('spanish rejection keeps the invoice issued', function () {
    $submission = submissionFor('ES');

    app(SubmissionResultRecorder::class)->record($submission, new SubmissionResult(SubmissionStatus::Rejected));

    expect($submission->invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
});

test('failure returns the invoice to draft in both countries', function (string $isoCode) {
    $submission = submissionFor($isoCode, SubmissionStatus::Pending);

    app(SubmissionResultRecorder::class)->record($submission, SubmissionResult::failed('Invalid credentials'));

    expect($submission->fresh()->error_message)->toBe('Invalid credentials');
    expect($submission->invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
})->with(['IT', 'ES']);

test('a late non-final result does not reopen a final submission', function () {
    $submission = submissionFor('ES', SubmissionStatus::Accepted);

    app(SubmissionResultRecorder::class)->record($submission, new SubmissionResult(SubmissionStatus::Submitted));

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Accepted);
});

test('known values are not erased and documents are stored', function () {
    Storage::fake('local');
    $submission = submissionFor('ES');
    $submission->update(['qr_code' => 'QR', 'external_id' => 'ext-1']);

    app(SubmissionResultRecorder::class)->record($submission, new SubmissionResult(SubmissionStatus::Accepted, document: '{"ok":true}'));

    $submission->refresh();
    expect($submission->qr_code)->toBe('QR');
    expect($submission->external_id)->toBe('ext-1');
    Storage::disk('local')->assertExists($submission->payload_path);
});

test('a stale rejection after delivery does not reopen an italian invoice', function () {
    $submission = submissionFor('IT', SubmissionStatus::Delivered);

    app(SubmissionResultRecorder::class)->record($submission, new SubmissionResult(SubmissionStatus::Rejected, 'NS'));

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Delivered);
    expect($submission->invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
});
