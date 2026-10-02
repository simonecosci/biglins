<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;

beforeEach(function () {
    $this->company = Company::factory()->create(['is_default' => true]);
    $this->invoice = Invoice::factory()->issued()->create(['company_id' => $this->company->id, 'paid' => false, 'note' => null]);
    $this->row = InvoiceRow::factory()->for($this->invoice)->create(['description' => 'Original', 'price' => 10]);
    $this->actingAs(User::factory()->create());
});

test('issued invoices cannot be deleted', function () {
    $this->delete(route('invoices.destroy', $this->invoice))->assertRedirect();

    expect(Invoice::query()->whereKey($this->invoice->id)->exists())->toBeTrue();
});

test('issued invoices only accept paid and note changes', function () {
    $this->put(route('invoices.update', $this->invoice), [
        'invoice_date' => '2020-01-01',
        'paid' => true,
        'note' => 'Paid by bank transfer',
        'customer_id' => $this->invoice->customer_id,
        'language' => $this->invoice->language,
        'rows' => [['id' => $this->row->id, 'description' => 'Changed', 'quantity' => 1, 'price' => 999, 'vat_rate' => 0]],
    ])->assertRedirect();

    $invoice = $this->invoice->fresh();
    expect($invoice->paid)->toBeTrue();
    expect($invoice->note)->toBe('Paid by bank transfer');
    expect($invoice->invoice_date->format('Y-m-d'))->not->toBe('2020-01-01');
    expect($this->row->fresh()->description)->toBe('Original');
    expect((float) $this->row->fresh()->price)->toBe(10.0);
});

test('drafts remain editable and deletable', function () {
    $draft = Invoice::factory()->create(['company_id' => $this->company->id]);

    $this->delete(route('invoices.destroy', $draft))->assertRedirect(route('invoices.index'));

    expect(Invoice::query()->whereKey($draft->id)->exists())->toBeFalse();
});

test('edit page exposes the lock', function () {
    $this->get(route('invoices.edit', $this->invoice))
        ->assertInertia(fn ($page) => $page->where('invoice.status', 'issued')->where('isLocked', true));
});

test('issued invoices accept a payload with only paid and note', function () {
    $this->put(route('invoices.update', $this->invoice), ['paid' => true, 'note' => 'x'])->assertRedirect();

    expect($this->invoice->fresh()->paid)->toBeTrue();
    expect($this->invoice->fresh()->note)->toBe('x');
});

test('a draft that already has a number cannot be deleted', function () {
    $draft = Invoice::factory()->draft()->create(['company_id' => $this->company->id, 'number' => '2026-0099']);

    $this->delete(route('invoices.destroy', $draft))->assertRedirect(route('invoices.edit', $draft));

    expect(Invoice::query()->whereKey($draft->id)->exists())->toBeTrue();
    expect(session('inertia.flash_data.toast.message'))->toBe('A numbered invoice cannot be deleted.');
});
