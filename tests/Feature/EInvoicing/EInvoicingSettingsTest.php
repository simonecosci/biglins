<?php

use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Providers\FakeProvider;
use App\Models\Company;
use App\Models\Country;
use App\Models\EInvoicingIntegration;
use App\Models\User;

beforeEach(function () {
    $this->company = Company::factory()->create(['country_id' => Country::factory()->spain()]);
    $this->actingAs(User::factory()->create());
});

test('the settings page lists drivers for the company country and never exposes credentials', function () {
    EInvoicingIntegration::factory()->create([
        'company_id' => $this->company->id,
        'driver' => EInvoicingDriver::B2Brouter,
        'credentials' => ['api_key' => 'super-secret', 'account_id' => '42'],
    ]);

    $response = $this->get(route('companies.e-invoicing.edit', $this->company));

    $response->assertInertia(fn ($page) => $page
        ->component('companies/EInvoicing')
        ->where('integration.configured_credentials', ['api_key', 'account_id'])
        ->where('drivers.0.value', 'b2brouter')
        ->whereNot('webhookUrl', null));
    expect($response->getContent())->not->toContain('super-secret');
});

test('credentials are saved encrypted', function () {
    $this->put(route('companies.e-invoicing.update', $this->company), [
        'driver' => 'b2brouter', 'environment' => 'sandbox', 'is_active' => true,
        'credentials' => ['api_key' => 'test_abc', 'account_id' => '42'],
    ])->assertRedirect(route('companies.e-invoicing.edit', $this->company));

    $integration = $this->company->eInvoicingIntegration()->firstOrFail();
    expect($integration->credentials)->toBe(['api_key' => 'test_abc', 'account_id' => '42']);
    expect($integration->is_active)->toBeTrue();
});

test('blank credential fields keep the stored value', function () {
    EInvoicingIntegration::factory()->create([
        'company_id' => $this->company->id, 'driver' => EInvoicingDriver::B2Brouter,
        'credentials' => ['api_key' => 'old-key', 'account_id' => '42'],
    ]);

    $this->put(route('companies.e-invoicing.update', $this->company), [
        'driver' => 'b2brouter', 'environment' => 'staging', 'is_active' => true,
        'credentials' => ['api_key' => '', 'account_id' => '43'],
    ])->assertSessionHasNoErrors();

    expect($this->company->eInvoicingIntegration()->first()->credentials)->toBe(['api_key' => 'old-key', 'account_id' => '43']);
});

test('switching driver drops the old credentials and validates the new ones', function () {
    EInvoicingIntegration::factory()->create(['company_id' => $this->company->id, 'driver' => EInvoicingDriver::Fake, 'credentials' => ['api_key' => 'stale']]);

    $this->put(route('companies.e-invoicing.update', $this->company), [
        'driver' => 'b2brouter', 'environment' => 'sandbox', 'is_active' => true, 'credentials' => [],
    ])->assertSessionHasErrors(['credentials.api_key', 'credentials.account_id']);
});

test('drivers not supporting the company country are refused', function () {
    $this->company->update(['country_id' => Country::factory()->create(['iso_code' => 'FR'])->id]);

    $this->put(route('companies.e-invoicing.update', $this->company), [
        'driver' => 'b2brouter', 'environment' => 'sandbox', 'is_active' => true,
        'credentials' => ['api_key' => 'k', 'account_id' => '1'],
    ])->assertSessionHasErrors('driver');
});

test('test connection reports a failure', function () {
    EInvoicingIntegration::factory()->create(['company_id' => $this->company->id]);
    FakeProvider::$connectionSucceeds = false;

    $this->post(route('companies.e-invoicing.test', $this->company))
        ->assertRedirect(route('companies.e-invoicing.edit', $this->company));
    expect(session('inertia.flash_data.toast.type'))->toBe('error');
});

test('the desktop build hides the webhook url', function () {
    config(['nativephp-internal.running' => true]);
    EInvoicingIntegration::factory()->create(['company_id' => $this->company->id]);

    $this->get(route('companies.e-invoicing.edit', $this->company))
        ->assertInertia(fn ($page) => $page->where('webhookUrl', null));
});
