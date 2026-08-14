<?php

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;
use Illuminate\Support\Carbon;

test('renewing a group creates a new invoice with copied rows one year later and cancels the source rows', function () {
    Carbon::setTestNow('2026-08-13');
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $customer = Customer::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'language' => 'it']);
    $subscriptionRow = InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Hosting',
        'quantity' => 1,
        'price' => 100,
        'vat_rate' => 22,
        'expiration_date' => '2026-08-01',
    ]);
    $plainRow = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => null]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('subscriptions.renew', $invoice));

    $newInvoice = Invoice::query()->where('id', '!=', $invoice->id)->firstOrFail();
    $response->assertRedirect(route('invoices.edit', $newInvoice));

    expect($newInvoice->company_id)->toBe($company->id);
    expect($newInvoice->customer_id)->toBe($customer->id);
    expect($newInvoice->language)->toBe('it');
    expect($newInvoice->paid)->toBeFalse();
    expect($newInvoice->rows)->toHaveCount(1);

    $newRow = $newInvoice->rows->first();
    expect($newRow->description)->toBe('Hosting');
    expect($newRow->expiration_date->format('Y-m-d'))->toBe('2027-08-01');
    expect($newRow->subscription_status)->toBe(SubscriptionStatus::Active);

    expect($subscriptionRow->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($plainRow->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);

    Carbon::setTestNow();
});

test('renewing a group only touches active subscription rows, leaving cancelled and plain rows untouched', function () {
    Carbon::setTestNow('2026-08-13');
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $customer = Customer::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'language' => 'it']);
    $activeRowA = InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Hosting',
        'expiration_date' => '2026-08-01',
        'subscription_status' => SubscriptionStatus::Active,
    ]);
    $activeRowB = InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Domain',
        'expiration_date' => '2026-09-15',
        'subscription_status' => SubscriptionStatus::Active,
    ]);
    $cancelledRow = InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Old service',
        'expiration_date' => '2026-08-01',
        'subscription_status' => SubscriptionStatus::Cancelled,
    ]);
    $plainRow = InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'One-off',
        'expiration_date' => null,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('subscriptions.renew', $invoice));

    $newInvoice = Invoice::query()->where('id', '!=', $invoice->id)->firstOrFail();
    $response->assertRedirect(route('invoices.edit', $newInvoice));

    expect($newInvoice->rows)->toHaveCount(2);
    expect($newInvoice->rows->firstWhere('description', 'Hosting')->expiration_date->format('Y-m-d'))->toBe('2027-08-01');
    expect($newInvoice->rows->firstWhere('description', 'Domain')->expiration_date->format('Y-m-d'))->toBe('2027-09-15');

    expect($activeRowA->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($activeRowB->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);

    expect($cancelledRow->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($cancelledRow->fresh()->expiration_date->format('Y-m-d'))->toBe('2026-08-01');
    expect($plainRow->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
    expect($plainRow->fresh()->expiration_date)->toBeNull();

    expect($newInvoice->rows->pluck('description'))->not->toContain('Old service');
    expect($newInvoice->rows->pluck('description'))->not->toContain('One-off');

    Carbon::setTestNow();
});

test('renewing a group with no active subscription rows returns a 404', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => null]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('subscriptions.renew', $invoice));

    $response->assertNotFound();
});

test('renewing a group from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-09-01']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('subscriptions.renew', $invoice));

    $response->assertForbidden();
});

test('cancelling a single row marks only that row as cancelled', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $row = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-09-01']);
    $otherRow = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-09-01']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('invoice-rows.cancel', $row));

    $response->assertRedirect(route('dashboard'));
    expect($row->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($otherRow->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

test('cancelling a row from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);
    $row = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-09-01']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('invoice-rows.cancel', $row));

    $response->assertForbidden();
});

test('cancelling a group marks all its active subscription rows as cancelled, leaving plain rows alone', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $rowA = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-09-01']);
    $rowB = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-10-01']);
    $plainRow = InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => null]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('subscriptions.cancel', $invoice));

    $response->assertRedirect(route('dashboard'));
    expect($rowA->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($rowB->fresh()->subscription_status)->toBe(SubscriptionStatus::Cancelled);
    expect($plainRow->fresh()->subscription_status)->toBe(SubscriptionStatus::Active);
});

test('cancelling a group from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'expiration_date' => '2026-09-01']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('subscriptions.cancel', $invoice));

    $response->assertForbidden();
});
