<?php

use App\Actions\IssueInvoice;
use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\SubmissionStatus;
use App\EInvoicing\Providers\FakeProvider;
use App\Enums\InvoiceStatus;
use App\Jobs\SubmitInvoice;
use App\Models\Company;
use App\Models\Country;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function draftWithRow(array $companyAttributes = []): Invoice
{
    $company = Company::factory()->create(['is_default' => true, 'country_id' => Country::factory()->create(['iso_code' => 'FR']), ...$companyAttributes]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->for($invoice)->create(['vat_rate' => 20]);

    return $invoice;
}

test('issuing a draft in a country without rules assigns number and locks it', function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    $invoice = draftWithRow();

    $issued = app(IssueInvoice::class)->handle($invoice);

    expect($issued->status)->toBe(InvoiceStatus::Issued);
    expect($issued->number)->toBe('2026-0001');
    expect($issued->issued_at->toDateTimeString())->toBe('2026-10-02 10:00:00');
    expect($issued->isLocked())->toBeTrue();
    expect($issued->submissions()->count())->toBe(0);

    Carbon::setTestNow();
});

test('issuing keeps an existing number', function () {
    $invoice = draftWithRow();
    $invoice->forceFill(['number' => '2026-0042'])->save();

    expect(app(IssueInvoice::class)->handle($invoice)->number)->toBe('2026-0042');
});

test('issued invoices get consecutive numbers and deleted drafts leave no gaps', function () {
    Carbon::setTestNow('2026-10-02');
    $first = draftWithRow();
    $company = $first->company;
    $deleted = Invoice::factory()->create(['company_id' => $company->id]);
    $second = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->for($second)->create();

    app(IssueInvoice::class)->handle($first);
    $deleted->delete();
    app(IssueInvoice::class)->handle($second);

    expect([$first->fresh()->number, $second->fresh()->number])->toBe(['2026-0001', '2026-0002']);

    Carbon::setTestNow();
});

test('issuing an issued invoice is rejected without a new number', function () {
    $invoice = draftWithRow();
    app(IssueInvoice::class)->handle($invoice);

    expect(fn () => app(IssueInvoice::class)->handle($invoice->fresh()))->toThrow(ValidationException::class);
    expect(Invoice::query()->whereNotNull('number')->count())->toBe(1);
});

test('compliance errors prevent issuing', function () {
    $company = Company::factory()->create(['country_id' => Country::factory()->italy(), 'vat_number' => null]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->for($invoice)->create();

    try {
        app(IssueInvoice::class)->handle($invoice);
        $this->fail('Expected a validation exception');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('company.vat_number');
    }

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
    expect($invoice->fresh()->number)->toBeNull();
});

test('countries that need a submission require an active integration', function () {
    $company = Company::factory()->create([
        'country_id' => Country::factory()->spain(), 'vat_number' => 'B12345678',
    ]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $invoice->customer->update(['vat_number' => 'B87654321', 'country_id' => $company->country_id]);
    InvoiceRow::factory()->for($invoice)->create(['vat_rate' => 21]);

    expect(fn () => app(IssueInvoice::class)->handle($invoice))
        ->toThrow(ValidationException::class, __('Configure electronic invoicing for this company before issuing invoices.'));
});

test('issue route issues the current company invoice', function () {
    $invoice = draftWithRow();

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.issue', $invoice))
        ->assertRedirect(route('invoices.edit', $invoice));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
});

test('issue route returns errors to the page', function () {
    $invoice = draftWithRow();
    $invoice->rows()->delete();

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.issue', $invoice))
        ->assertSessionHasErrors('rows');
});

test('issue route refuses invoices of another company', function () {
    $invoice = draftWithRow();
    Company::query()->update(['is_default' => false]);
    Company::factory()->create(['is_default' => true]);

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.issue', $invoice))
        ->assertForbidden();
});

test('issue route rejects an already issued invoice', function () {
    $invoice = draftWithRow();
    app(IssueInvoice::class)->handle($invoice);

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.issue', $invoice))
        ->assertSessionHasErrors('invoice');
});

function spanishDraft(): Invoice
{
    $spain = Country::factory()->spain()->create();
    $company = Company::factory()->create(['is_default' => true, 'country_id' => $spain->id, 'vat_number' => 'B12345678']);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $invoice->customer->update(['vat_number' => 'B87654321', 'country_id' => $spain->id]);
    InvoiceRow::factory()->for($invoice)->create(['vat_rate' => 21]);

    return $invoice;
}

test('issuing with an active integration creates and sends a submission', function () {
    $invoice = spanishDraft();
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id]);

    app(IssueInvoice::class)->handle($invoice);

    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Submitted);
    expect(FakeProvider::$sentInvoiceIds)->toBe([$invoice->id]);
});

test('an inactive integration is not enough', function () {
    $invoice = spanishDraft();
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id, 'is_active' => false]);

    expect(fn () => app(IssueInvoice::class)->handle($invoice))->toThrow(ValidationException::class);
});

test('a failed submission can be retried by issuing again with the same number', function () {
    $invoice = spanishDraft();
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id]);
    FakeProvider::queueSendResult(SubmissionResult::failed('Bad payload'));

    app(IssueInvoice::class)->handle($invoice);
    $number = $invoice->fresh()->number;
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);

    app(IssueInvoice::class)->handle($invoice->fresh());

    expect($invoice->fresh()->number)->toBe($number);
    expect($invoice->submissions()->count())->toBe(2);
    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Submitted);
});

test('issue route reports a failed synchronous submission', function () {
    $invoice = spanishDraft();
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id]);
    FakeProvider::queueSendResult(SubmissionResult::failed('Bad payload'));

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.issue', $invoice))
        ->assertRedirect(route('invoices.edit', $invoice));

    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Failed);
    expect(session('inertia.flash_data.toast.type'))->toBe('error');
    expect(session('inertia.flash_data.toast.message'))->toContain('Bad payload');
});

test('a draft that already has an open submission cannot be issued again', function (SubmissionStatus $status) {
    $invoice = spanishDraft();
    $integration = EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id]);
    $invoice->submissions()->create(['e_invoicing_integration_id' => $integration->id, 'driver' => $integration->driver, 'status' => $status]);

    try {
        app(IssueInvoice::class)->handle($invoice);
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('invoice');
    }

    expect($invoice->submissions()->count())->toBe(1);
    expect(FakeProvider::$sentInvoiceIds)->toBe([]);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
})->with([SubmissionStatus::Pending, SubmissionStatus::Submitted]);

test('the desktop build submits inline because no queue workers run', function () {
    config(['nativephp-internal.running' => true, 'queue.default' => 'database']);
    $invoice = spanishDraft();
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id]);

    app(IssueInvoice::class)->handle($invoice);

    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Submitted);
    expect(FakeProvider::$sentInvoiceIds)->toBe([$invoice->id]);
});

test('outside the desktop build the submission is queued, not run', function () {
    Queue::fake();
    $invoice = spanishDraft();
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id]);

    app(IssueInvoice::class)->handle($invoice);

    Queue::assertPushed(SubmitInvoice::class);
    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Pending);
    expect(FakeProvider::$sentInvoiceIds)->toBe([]);
});

test('an unexpected error during the inline desktop submission is recorded as a failure', function () {
    config(['nativephp-internal.running' => true, 'queue.default' => 'database']);
    $invoice = spanishDraft();
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id]);
    FakeProvider::queueSendResult(new RuntimeException('boom'));

    app(IssueInvoice::class)->handle($invoice);

    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Failed);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});
