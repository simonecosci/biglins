<?php

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;
use Illuminate\Support\Carbon;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard shares the current company and the full company list', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['is_default' => true]);
    Company::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('currentCompany.id', $company->id)
        ->where('currentCompany.name', $company->name)
        ->has('companies', 2)
    );
});

test('dashboard shares a null current company when none exist', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('currentCompany', null));
});

test('guests are not shared any company data even when companies exist', function () {
    Company::factory()->create(['is_default' => true]);

    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('currentCompany', null)
        ->where('companies', [])
    );
});

test('dashboard shares subscription KPIs and invoice-grouped rows scoped to the current company', function () {
    Carbon::setTestNow('2026-08-13');
    $user = User::factory()->create();
    $company = Company::factory()->create(['is_default' => true]);
    $otherCompany = Company::factory()->create();
    $customer = Customer::factory()->create(['name' => 'Acme Srl']);
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'description' => 'Domain', 'expiration_date' => '2026-08-01']);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'description' => 'Hosting', 'expiration_date' => '2026-08-20']);

    $otherInvoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);
    InvoiceRow::factory()->create(['invoice_id' => $otherInvoice->id, 'expiration_date' => '2026-08-01']);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('subscriptions.expiredCount', 1)
        ->where('subscriptions.expiringSoonCount', 1)
        ->has('subscriptions.groups', 1)
        ->where('subscriptions.groups.0.invoice_id', $invoice->id)
        ->where('subscriptions.groups.0.customer_name', 'Acme Srl')
        ->where('subscriptions.groups.0.status', 'expired')
        ->has('subscriptions.groups.0.rows', 2)
    );

    Carbon::setTestNow();
});

test('dashboard shares year-to-date revenue for the current company only', function () {
    Carbon::setTestNow('2026-08-13');
    $user = User::factory()->create();
    $company = Company::factory()->create(['is_default' => true]);
    $otherCompany = Company::factory()->create();

    $inYear = Invoice::factory()->create(['company_id' => $company->id, 'invoice_date' => '2026-02-01']);
    InvoiceRow::factory()->create(['invoice_id' => $inYear->id, 'quantity' => 1, 'price' => 100, 'vat_rate' => 22]);

    $alsoInYear = Invoice::factory()->create(['company_id' => $company->id, 'invoice_date' => '2026-08-13', 'paid' => false]);
    InvoiceRow::factory()->create(['invoice_id' => $alsoInYear->id, 'quantity' => 1, 'price' => 50, 'vat_rate' => 0]);

    $lastYear = Invoice::factory()->create(['company_id' => $company->id, 'invoice_date' => '2025-12-31']);
    InvoiceRow::factory()->create(['invoice_id' => $lastYear->id, 'quantity' => 1, 'price' => 9999, 'vat_rate' => 22]);

    $otherCompanyInvoice = Invoice::factory()->create(['company_id' => $otherCompany->id, 'invoice_date' => '2026-05-01']);
    InvoiceRow::factory()->create(['invoice_id' => $otherCompanyInvoice->id, 'quantity' => 1, 'price' => 5000, 'vat_rate' => 22]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('revenue.year', 2026)
        ->where('revenue.yearToDate', 172)
    );

    Carbon::setTestNow();
});

test('dashboard excludes cancelled subscription rows', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['is_default' => true]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'expiration_date' => '2026-08-01',
        'subscription_status' => SubscriptionStatus::Cancelled,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('subscriptions.expiredCount', 0)
        ->has('subscriptions.groups', 0)
    );
});
