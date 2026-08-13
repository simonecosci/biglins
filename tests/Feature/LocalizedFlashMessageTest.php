<?php

use App\Models\Country;
use App\Models\User;

test('flash messages are translated for an Italian-locale user', function () {
    $user = User::factory()->create(['locale' => 'it']);

    $this->actingAs($user)->post(route('countries.store'), ['name' => 'Test Country']);

    expect(session('inertia.flash_data.toast.message'))->toBe('Paese creato.');
});

test('flash messages are translated for a Spanish-locale user', function () {
    $user = User::factory()->create(['locale' => 'es']);

    $this->actingAs($user)->post(route('countries.store'), ['name' => 'Test Country']);

    expect(session('inertia.flash_data.toast.message'))->toBe('País creado.');
});

test('flash messages stay in English by default', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)->post(route('countries.store'), ['name' => 'Test Country']);

    expect(session('inertia.flash_data.toast.message'))->toBe('Country created.');
});
