<?php

use App\Models\User;

test('validation errors are translated for an Italian-locale user', function () {
    $user = User::factory()->create(['locale' => 'it']);

    $response = $this->actingAs($user)->post(route('countries.store'), []);

    $response->assertSessionHasErrors([
        'name' => 'Il campo name è obbligatorio.',
    ]);
});

test('validation errors are translated for a Spanish-locale user', function () {
    $user = User::factory()->create(['locale' => 'es']);

    $response = $this->actingAs($user)->post(route('countries.store'), []);

    $response->assertSessionHasErrors([
        'name' => 'El campo name es obligatorio.',
    ]);
});

test('validation errors stay in English by default', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $response = $this->actingAs($user)->post(route('countries.store'), []);

    $response->assertSessionHasErrors([
        'name' => 'The name field is required.',
    ]);
});
