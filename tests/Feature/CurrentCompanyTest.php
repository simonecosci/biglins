<?php

use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Str;

test('resolve uses the company stored in the session when it exists', function () {
    $company = Company::factory()->create();
    Company::factory()->create(['is_default' => true]);

    session(['current_company_id' => $company->id]);

    expect(CurrentCompany::resolve()->id)->toBe($company->id);
});

test('resolve falls back to the default company when the session is empty', function () {
    Company::factory()->create();
    $default = Company::factory()->create(['is_default' => true]);

    expect(CurrentCompany::resolve()->id)->toBe($default->id);
});

test('resolve falls back to the first company by name when there is no default', function () {
    Company::factory()->create(['name' => 'Bravo Inc']);
    $alpha = Company::factory()->create(['name' => 'Alpha Inc']);

    expect(CurrentCompany::resolve()->id)->toBe($alpha->id);
});

test('resolve falls back to the default company when the session id does not exist', function () {
    $default = Company::factory()->create(['is_default' => true]);

    session(['current_company_id' => (string) Str::uuid()]);

    expect(CurrentCompany::resolve()->id)->toBe($default->id);
});

test('resolve returns null when there are no companies at all', function () {
    expect(CurrentCompany::resolve())->toBeNull();
});

test('switching the current company updates the session', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->put(route('current-company.update'), [
        'company_id' => $company->id,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect();
    expect(session('current_company_id'))->toBe($company->id);
});

test('switching to a company that does not exist fails validation', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->put(route('current-company.update'), [
        'company_id' => (string) Str::uuid(),
    ]);

    $response->assertSessionHasErrors('company_id');
});

test('switching the current company without a company_id fails validation', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->put(route('current-company.update'), []);

    $response->assertSessionHasErrors('company_id');
});

test('guests are redirected to the login page when switching the current company', function () {
    $company = Company::factory()->create();

    $response = $this->put(route('current-company.update'), ['company_id' => $company->id]);

    $response->assertRedirect(route('login'));
});
