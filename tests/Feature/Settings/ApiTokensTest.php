<?php

use App\Models\User;

test('guests are redirected to the login page when visiting api tokens settings', function () {
    $this->get(route('api-tokens.index'))->assertRedirect(route('login'));
});

test('api tokens settings page lists the user\'s tokens', function () {
    $user = User::factory()->create();
    $user->createToken('laptop');

    $response = $this->actingAs($user)->get(route('api-tokens.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('settings/ApiTokens')
        ->has('tokens', 1)
        ->where('tokens.0.name', 'laptop'));
});

test('a token can be created and the plaintext value is returned once', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('api-tokens.store'), [
        'name' => 'agent-1',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('api-tokens.index'));
    expect($user->fresh()->tokens)->toHaveCount(1);
    expect($user->fresh()->tokens->first()->name)->toBe('agent-1');
});

test('a token name is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('api-tokens.store'), ['name' => '']);

    $response->assertSessionHasErrors('name');
});

test('a token can be revoked', function () {
    $user = User::factory()->create();
    $token = $user->createToken('laptop');

    $response = $this->actingAs($user)->delete(route('api-tokens.destroy', $token->accessToken->id));

    $response->assertRedirect(route('api-tokens.index'));
    expect($user->fresh()->tokens)->toHaveCount(0);
});

test('a user cannot revoke another user\'s token', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $token = $otherUser->createToken('laptop');

    $response = $this->actingAs($user)->delete(route('api-tokens.destroy', $token->accessToken->id));

    $response->assertForbidden();
    expect($otherUser->fresh()->tokens)->toHaveCount(1);
});
