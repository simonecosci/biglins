<?php

use App\Models\User;

test('authenticated user locale is applied to the app', function () {
    $user = User::factory()->create(['locale' => 'it']);

    $this->actingAs($user)->get('/dashboard');

    expect(app()->getLocale())->toBe('it');
});

test('guest locale falls back to Accept-Language header', function () {
    $this->withHeaders(['Accept-Language' => 'es-ES,es;q=0.9'])
        ->get('/login');

    expect(app()->getLocale())->toBe('es');
});

test('guest locale defaults to en when Accept-Language is unsupported or missing', function () {
    $this->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9'])
        ->get('/login');

    expect(app()->getLocale())->toBe('en');
});

test('locale and locales are shared with every Inertia response', function () {
    $user = User::factory()->create(['locale' => 'it']);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page
        ->where('locale', 'it')
        ->where('locales', ['en', 'it', 'es'])
    );
});
