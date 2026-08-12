<?php

use App\Models\Country;
use App\Models\Customer;

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

use App\Models\User;
use Illuminate\Support\Str;

test('guests are redirected to the login page when visiting customers', function () {
    $this->get(route('customers.index'))->assertRedirect(route('login'));
});

test('customers index page can be rendered', function () {
    $user = User::factory()->create();
    Customer::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('customers.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('customers/Index'));
});

test('customer can be created with only a name', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('customers.store'), [
        'name' => 'Acme Corp',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('customers.index'));
    expect(Customer::query()->where('name', 'Acme Corp')->exists())->toBeTrue();
});

test('customer name is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('customers.store'), [
        'name' => '',
    ]);

    $response->assertSessionHasErrors('name');
});

test('customer email must be a valid address when present', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('customers.store'), [
        'name' => 'Acme Corp',
        'email' => 'not-an-email',
    ]);

    $response->assertSessionHasErrors('email');
});

test('customer web must be a valid url when present', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('customers.store'), [
        'name' => 'Acme Corp',
        'web' => 'not-a-url',
    ]);

    $response->assertSessionHasErrors('web');
});

test('customer country_id must reference an existing country', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('customers.store'), [
        'name' => 'Acme Corp',
        'country_id' => (string) Str::uuid(),
    ]);

    $response->assertSessionHasErrors('country_id');
});

test('customer can be created with a valid country', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create();

    $response = $this->actingAs($user)->post(route('customers.store'), [
        'name' => 'Acme Corp',
        'country_id' => $country->id,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('customers.index'));
    expect(Customer::query()->where('name', 'Acme Corp')->first()->country_id)->toBe($country->id);
});

test('customer can be updated', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->put(route('customers.update', $customer), [
        'name' => 'New Name',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('customers.index'));
    expect($customer->fresh()->name)->toBe('New Name');
});

test('customer can be deleted', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();

    $response = $this->actingAs($user)->delete(route('customers.destroy', $customer));

    $response->assertRedirect(route('customers.index'));
    expect(Customer::query()->find($customer->id))->toBeNull();
});
