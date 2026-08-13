<?php

use App\Models\User;

test('language settings page is displayed', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('language.edit'));

    $response->assertOk();
});

test('user can update their locale', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $response = $this->actingAs($user)->put(route('language.update'), [
        'locale' => 'it',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('language.edit'));

    expect($user->refresh()->locale)->toBe('it');
});

test('locale must be one of the supported languages', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $response = $this->actingAs($user)->put(route('language.update'), [
        'locale' => 'fr',
    ]);

    $response->assertSessionHasErrors('locale');
    expect($user->refresh()->locale)->toBe('en');
});

test('guests cannot update locale', function () {
    $this->put(route('language.update'), ['locale' => 'it'])
        ->assertRedirect(route('login'));
});
