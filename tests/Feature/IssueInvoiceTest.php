<?php

use App\Actions\IssueInvoice;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;
use Illuminate\Support\Carbon;
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
