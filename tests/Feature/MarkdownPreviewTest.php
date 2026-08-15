<?php

use App\Models\User;

test('guests are redirected to the login page when requesting a markdown preview', function () {
    $this->post(route('estimations.markdown-preview'), ['body' => '# Hi'])
        ->assertRedirect(route('login'));
});

test('markdown preview renders the given body to html', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('estimations.markdown-preview'), [
        'body' => "# Hello\n\nSome **bold** text.",
    ]);

    $response->assertOk();
    expect($response->json('html'))->toContain('<h1>Hello</h1>');
    expect($response->json('html'))->toContain('<strong>bold</strong>');
});

test('markdown preview returns an empty string for an empty body', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('estimations.markdown-preview'), ['body' => '']);

    $response->assertOk();
    expect($response->json('html'))->toBe('');
});

test('markdown preview strips raw html so scripts cannot survive rendering', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('estimations.markdown-preview'), [
        'body' => "Hello\n\n<script>alert(1)</script>",
    ]);

    $response->assertOk();
    expect($response->json('html'))->not->toContain('<script>');
});

test('markdown preview does not emit a javascript: link as an href', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('estimations.markdown-preview'), [
        'body' => '[click me](javascript:alert(1))',
    ]);

    $response->assertOk();
    expect($response->json('html'))->not->toContain('href="javascript:');
});
