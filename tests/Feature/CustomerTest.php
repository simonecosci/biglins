<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

test('customer belongs to a company', function () {
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id]);

    expect($customer->company)->toBeInstanceOf(Company::class);
    expect($customer->company->id)->toBe($company->id);
});

test('a company can have many customers', function () {
    $company = Company::factory()->create();
    Customer::factory()->count(2)->create(['company_id' => $company->id]);

    expect($company->fresh()->customers)->toHaveCount(2);
});

test('a customer requires a company_id at the database level', function () {
    expect(fn () => Customer::factory()->create(['company_id' => null]))
        ->toThrow(QueryException::class);
});

test('customer factory creates a customer belonging to a country', function () {
    $customer = Customer::factory()->create();

    expect($customer->id)->toBeString();
    expect(strlen($customer->id))->toBe(36);
    expect($customer->country)->toBeInstanceOf(Country::class);
});

test('customer can be created without a country', function () {
    $customer = Customer::factory()->create(['country_id' => null]);

    expect($customer->country_id)->toBeNull();
    expect($customer->country)->toBeNull();
});

test('guests are redirected to the login page when visiting customers', function () {
    $this->get(route('customers.index'))->assertRedirect(route('login'));
});

test('customers index page can be rendered', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Customer::factory()->count(3)->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('customers.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('customers/Index'));
});

test('customers index only lists customers for the current company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Customer::factory()->count(2)->create(['company_id' => $company->id]);
    Customer::factory()->count(3)->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('customers.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('customers.data', 2));
});

test('customers index renders with an empty state when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('customers.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('customers.data', 0));
});

test('customer create page redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('customers.create'));

    $response->assertRedirect(route('companies.create'));
});

test('customer store redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('customers.store'), [
        'name' => 'Acme Corp',
    ]);

    $response->assertRedirect(route('companies.create'));
    expect(Customer::query()->where('name', 'Acme Corp')->exists())->toBeFalse();
});

test('customer can be created with only a name', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('customers.store'), [
        'name' => 'Acme Corp',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('customers.index'));
    $customer = Customer::query()->where('name', 'Acme Corp')->firstOrFail();
    expect($customer->company_id)->toBe($company->id);
});

test('customer store ignores a company_id sent in the payload and uses the current company instead', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('customers.store'), [
        'company_id' => $otherCompany->id,
        'name' => 'Acme Corp',
    ]);

    $customer = Customer::query()->where('name', 'Acme Corp')->firstOrFail();
    expect($customer->company_id)->toBe($company->id);
});

test('customer name is required', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('customers.store'), [
        'name' => '',
    ]);

    $response->assertSessionHasErrors('name');
});

test('customer email must be a valid address when present', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('customers.store'), [
        'name' => 'Acme Corp',
        'email' => 'not-an-email',
    ]);

    $response->assertSessionHasErrors('email');
});

test('customer web must be a valid url when present', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('customers.store'), [
        'name' => 'Acme Corp',
        'web' => 'not-a-url',
    ]);

    $response->assertSessionHasErrors('web');
});

test('customer country_id must reference an existing country', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('customers.store'), [
        'name' => 'Acme Corp',
        'country_id' => (string) Str::uuid(),
    ]);

    $response->assertSessionHasErrors('country_id');
});

test('customer can be created with a valid country', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $country = Country::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('customers.store'), [
        'name' => 'Acme Corp',
        'country_id' => $country->id,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('customers.index'));
    expect(Customer::query()->where('name', 'Acme Corp')->first()->country_id)->toBe($country->id);
});

test('customer can be updated', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id, 'name' => 'Old Name']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('customers.update', $customer), [
        'name' => 'New Name',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('customers.index'));
    expect($customer->fresh()->name)->toBe('New Name');
});

test('customer can be deleted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('customers.destroy', $customer));

    $response->assertRedirect(route('customers.index'));
    expect(Customer::query()->find($customer->id))->toBeNull();
});

test('viewing the edit page of a customer from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('customers.edit', $customer));

    $response->assertForbidden();
});

test('updating a customer from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $otherCompany->id, 'name' => 'Original Name']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('customers.update', $customer), [
        'name' => 'Hacked Name',
    ]);

    $response->assertForbidden();
    expect($customer->fresh()->name)->toBe('Original Name');
});

test('deleting a customer from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('customers.destroy', $customer));

    $response->assertForbidden();
    expect(Customer::query()->find($customer->id))->not->toBeNull();
});
