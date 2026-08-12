<?php

use App\Models\Country;

test('country factory creates a country with a uuid primary key', function () {
    $country = Country::factory()->create();

    expect($country->id)->toBeString();
    expect(strlen($country->id))->toBe(36);
});

test('country name must be unique at the database level', function () {
    Country::factory()->create(['name' => 'Italia']);

    expect(fn () => Country::factory()->create(['name' => 'Italia']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('seeding the countries table populates the standard country list', function () {
    $this->seed(\Database\Seeders\CountrySeeder::class);

    expect(Country::query()->count())->toBe(195);
    expect(Country::query()->where('name', 'Italia')->exists())->toBeTrue();
});

use App\Models\User;

test('guests are redirected to the login page when visiting countries', function () {
    $this->get(route('countries.index'))->assertRedirect(route('login'));
});

test('countries index page can be rendered', function () {
    $user = User::factory()->create();
    Country::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('countries.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('countries/Index'));
});

test('country can be created', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('countries.store'), [
        'name' => 'Wakanda',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('countries.index'));
    expect(Country::query()->where('name', 'Wakanda')->exists())->toBeTrue();
});

test('country name is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('countries.store'), [
        'name' => '',
    ]);

    $response->assertSessionHasErrors('name');
});

test('country name must be unique when created via the endpoint', function () {
    $user = User::factory()->create();
    Country::factory()->create(['name' => 'Italia']);

    $response = $this->actingAs($user)->post(route('countries.store'), [
        'name' => 'Italia',
    ]);

    $response->assertSessionHasErrors('name');
});

test('country can be updated', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->put(route('countries.update', $country), [
        'name' => 'New Name',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('countries.index'));
    expect($country->fresh()->name)->toBe('New Name');
});

test('updating a country does not conflict with its own name', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create(['name' => 'Italia']);

    $response = $this->actingAs($user)->put(route('countries.update', $country), [
        'name' => 'Italia',
    ]);

    $response->assertSessionHasNoErrors();
});

test('country can be deleted', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create();

    $response = $this->actingAs($user)->delete(route('countries.destroy', $country));

    $response->assertRedirect(route('countries.index'));
    expect(Country::query()->find($country->id))->toBeNull();
});
