<?php

use App\Models\Company;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard shares the current company and the full company list', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['is_default' => true]);
    Company::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('currentCompany.id', $company->id)
        ->where('currentCompany.name', $company->name)
        ->has('companies', 2)
    );
});

test('dashboard shares a null current company when none exist', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('currentCompany', null));
});
