<?php

use App\Actions\IssueInvoice;
use App\Actions\SubmitElectronicInvoice;
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

function spanishDraft(): Invoice
{
    $spain = Country::factory()->spain()->create();
    $company = Company::factory()->eInvoicingEnabled()->create(['is_default' => true, 'country_id' => $spain->id, 'vat_number' => 'B12345678']);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $invoice->customer->update(['vat_number' => 'B87654321', 'country_id' => $spain->id]);
    InvoiceRow::factory()->for($invoice)->create(['vat_rate' => 21]);

    return $invoice;
}

function spanishIssued(): Invoice
{
    $invoice = app(IssueInvoice::class)->handle(spanishDraft());
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id]);

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

test('issuing does not run the electronic invoicing checks when the company has it disabled', function () {
    $spain = Country::factory()->spain()->create();
    $company = Company::factory()->create(['country_id' => $spain->id, 'vat_number' => null]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $invoice->customer->update(['vat_number' => 'FR12345678901', 'country_id' => Country::factory()->create(['iso_code' => 'FR'])->id, 'fiscal_details' => null]);
    InvoiceRow::factory()->for($invoice)->create(['vat_rate' => 0, 'vat_exemption_code' => null]);

    $issued = app(IssueInvoice::class)->handle($invoice);

    expect($issued->status)->toBe(InvoiceStatus::Issued);
    expect($issued->submissions()->count())->toBe(0);
});

test('with electronic invoicing enabled, zero rated rows need an exemption code before issuing', function () {
    $invoice = spanishDraft();
    $invoice->rows()->update(['vat_rate' => 0, 'vat_exemption_code' => null]);

    try {
        app(IssueInvoice::class)->handle($invoice);
        $this->fail('Expected a validation exception');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['rows.0.vat_exemption_code']);
    }

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});

test('with electronic invoicing enabled, customer data is not checked when issuing', function () {
    $invoice = spanishDraft();
    $invoice->customer->update(['vat_number' => null]);

    expect(app(IssueInvoice::class)->handle($invoice)->status)->toBe(InvoiceStatus::Issued);
});

test('issuing never sends the invoice electronically', function () {
    $invoice = spanishDraft();
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id]);

    app(IssueInvoice::class)->handle($invoice);

    expect($invoice->submissions()->count())->toBe(0);
    expect(FakeProvider::$sentInvoiceIds)->toBe([]);
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

test('electronic invoicing is off by default for new companies', function () {
    expect(Company::factory()->create()->fresh()->einvoicing_enabled)->toBeFalse();
});

test('sending an issued invoice creates and sends a submission', function () {
    $invoice = spanishIssued();

    app(SubmitElectronicInvoice::class)->handle($invoice);

    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Submitted);
    expect(FakeProvider::$sentInvoiceIds)->toBe([$invoice->id]);
});

test('a draft cannot be sent electronically', function () {
    $invoice = spanishDraft();
    EInvoicingIntegration::factory()->create(['company_id' => $invoice->company_id]);

    expect(fn () => app(SubmitElectronicInvoice::class)->handle($invoice))
        ->toThrow(ValidationException::class, __('Issue the invoice before sending it electronically.'));
    expect($invoice->submissions()->count())->toBe(0);
});

test('invoices of a company without electronic invoicing cannot be sent', function () {
    $invoice = spanishIssued();
    $invoice->company->update(['einvoicing_enabled' => false]);

    expect(fn () => app(SubmitElectronicInvoice::class)->handle($invoice))
        ->toThrow(ValidationException::class, __('Electronic invoicing is not enabled for this company.'));
    expect($invoice->submissions()->count())->toBe(0);
});

test('sending runs the country checks and returns them without submitting', function () {
    $invoice = spanishIssued();
    $invoice->customer->update(['country_id' => Country::factory()->create(['iso_code' => 'FR'])->id, 'fiscal_details' => null]);

    try {
        app(SubmitElectronicInvoice::class)->handle($invoice);
        $this->fail('Expected a validation exception');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('customer.fiscal_details.id_type');
    }

    expect($invoice->submissions()->count())->toBe(0);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
});

test('sending requires a configured integration', function () {
    $invoice = app(IssueInvoice::class)->handle(spanishDraft());

    expect(fn () => app(SubmitElectronicInvoice::class)->handle($invoice))
        ->toThrow(ValidationException::class, __('Configure electronic invoicing for this company before sending invoices.'));
});

test('an inactive integration is not enough', function () {
    $invoice = spanishIssued();
    $invoice->company->eInvoicingIntegration->update(['is_active' => false]);

    expect(fn () => app(SubmitElectronicInvoice::class)->handle($invoice))->toThrow(ValidationException::class);
});

test('after a failed submission the invoice is issued again with the same number and resent', function () {
    $invoice = spanishIssued();
    $number = $invoice->number;
    FakeProvider::queueSendResult(SubmissionResult::failed('Bad payload'));

    app(SubmitElectronicInvoice::class)->handle($invoice);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);

    app(IssueInvoice::class)->handle($invoice->fresh());
    app(SubmitElectronicInvoice::class)->handle($invoice->fresh());

    expect($invoice->fresh()->number)->toBe($number);
    expect($invoice->submissions()->count())->toBe(2);
    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Submitted);
});

test('an invoice already sent electronically cannot be sent again', function (SubmissionStatus $status) {
    $invoice = spanishIssued();
    $integration = $invoice->company->eInvoicingIntegration;
    $invoice->submissions()->create(['e_invoicing_integration_id' => $integration->id, 'driver' => $integration->driver, 'status' => $status]);

    try {
        app(SubmitElectronicInvoice::class)->handle($invoice);
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('invoice');
    }

    expect($invoice->submissions()->count())->toBe(1);
    expect(FakeProvider::$sentInvoiceIds)->toBe([]);
})->with([SubmissionStatus::Pending, SubmissionStatus::Submitted, SubmissionStatus::Accepted]);

test('submit route sends the current company invoice', function () {
    $invoice = spanishIssued();

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.submit', $invoice))
        ->assertRedirect(route('invoices.edit', $invoice));

    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Submitted);
});

test('submit route returns the country checks to the page', function () {
    $invoice = spanishIssued();
    $invoice->customer->update(['vat_number' => null]);

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.submit', $invoice))
        ->assertSessionHasErrors('customer.vat_number');
});

test('submit route refuses invoices of another company', function () {
    $invoice = spanishIssued();
    Company::query()->update(['is_default' => false]);
    Company::factory()->create(['is_default' => true]);

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.submit', $invoice))
        ->assertForbidden();

    expect($invoice->submissions()->count())->toBe(0);
});

test('submit route reports a failed synchronous submission', function () {
    $invoice = spanishIssued();
    FakeProvider::queueSendResult(SubmissionResult::failed('Bad payload'));

    $this->actingAs(User::factory()->create())
        ->post(route('invoices.submit', $invoice))
        ->assertRedirect(route('invoices.edit', $invoice));

    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Failed);
    expect(session('inertia.flash_data.toast.type'))->toBe('error');
    expect(session('inertia.flash_data.toast.message'))->toContain('Bad payload');
});

test('the edit page offers electronic sending only when the company has it enabled', function (bool $enabled) {
    $invoice = spanishIssued();
    $invoice->company->update(['einvoicing_enabled' => $enabled]);

    $this->actingAs(User::factory()->create())
        ->get(route('invoices.edit', $invoice))
        ->assertInertia(fn ($page) => $page->where('usesElectronicInvoicing', $enabled));
})->with([true, false]);

test('the desktop build submits inline because no queue workers run', function () {
    config(['nativephp-internal.running' => true, 'queue.default' => 'database']);
    $invoice = spanishIssued();

    app(SubmitElectronicInvoice::class)->handle($invoice);

    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Submitted);
    expect(FakeProvider::$sentInvoiceIds)->toBe([$invoice->id]);
});

test('outside the desktop build the submission is queued, not run', function () {
    Queue::fake();
    $invoice = spanishIssued();

    app(SubmitElectronicInvoice::class)->handle($invoice);

    Queue::assertPushed(SubmitInvoice::class);
    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Pending);
    expect(FakeProvider::$sentInvoiceIds)->toBe([]);
});

test('an unexpected error during the inline desktop submission is recorded as a failure', function () {
    config(['nativephp-internal.running' => true, 'queue.default' => 'database']);
    $invoice = spanishIssued();
    FakeProvider::queueSendResult(new RuntimeException('boom'));

    app(SubmitElectronicInvoice::class)->handle($invoice);

    expect($invoice->fresh()->latestSubmission->status)->toBe(SubmissionStatus::Failed);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});
