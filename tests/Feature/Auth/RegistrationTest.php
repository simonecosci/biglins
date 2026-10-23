<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();

    $user = User::where('email', 'test@example.com')->first();
    $response->assertRedirect(route('dashboard'));
});

test('registration screen is not available once a user exists', function () {
    User::factory()->create();

    $this->get(route('register'))->assertNotFound();
});

test('new users cannot register once a user exists', function () {
    User::factory()->create();

    $response = $this->post(route('register.store'), [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
    $this->assertGuest();
    expect(User::where('email', 'intruder@example.com')->exists())->toBeFalse();
});

test('login and welcome pages offer registration only while no user exists', function () {
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canRegister', true));
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('canRegister', true));

    User::factory()->create();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canRegister', false));
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('canRegister', false));
});
