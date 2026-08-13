<?php

use App\Models\Company;
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
