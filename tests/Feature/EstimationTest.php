<?php

use App\Enums\EstimationStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

test('estimation factory creates an estimation with a uuid primary key', function () {
    $estimation = Estimation::factory()->create();

    expect($estimation->id)->toBeString();
    expect(strlen($estimation->id))->toBe(36);
    expect($estimation->customer)->toBeInstanceOf(Customer::class);
    expect($estimation->company)->toBeInstanceOf(Company::class);
    expect($estimation->status)->toBe(EstimationStatus::Pending);
});

test('an estimation requires a company_id at the database level', function () {
    expect(fn () => Estimation::factory()->create(['company_id' => null]))
        ->toThrow(QueryException::class);
});

test('first estimation of the year is numbered 0001', function () {
    Carbon::setTestNow('2026-01-15');
    $company = Company::factory()->create();

    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'number' => null]);

    expect($estimation->number)->toBe('2026-0001');

    Carbon::setTestNow();
});

test('subsequent estimations in the same year increment the sequence', function () {
    Carbon::setTestNow('2026-01-15');
    $company = Company::factory()->create();

    Estimation::factory()->create(['company_id' => $company->id, 'number' => null]);
    $second = Estimation::factory()->create(['company_id' => $company->id, 'number' => null]);

    expect($second->number)->toBe('2026-0002');

    Carbon::setTestNow();
});

test('estimation numbering is independent per company and independent from invoice numbering', function () {
    Carbon::setTestNow('2026-01-15');
    $company = Company::factory()->create();
    Estimation::factory()->create(['company_id' => $company->id, 'number' => null]);
    $otherCompany = Company::factory()->create();

    $firstForOther = Estimation::factory()->create(['company_id' => $otherCompany->id, 'number' => null]);

    expect($firstForOther->number)->toBe('2026-0001');

    Carbon::setTestNow();
});

test('estimation number must be unique per company at the database level', function () {
    $company = Company::factory()->create();
    Estimation::factory()->create(['company_id' => $company->id, 'number' => '2026-0001']);

    expect(fn () => Estimation::factory()->create(['company_id' => $company->id, 'number' => '2026-0001']))
        ->toThrow(QueryException::class);
});

test('isExpired is false for a pending estimation with a future expiration date', function () {
    Carbon::setTestNow('2026-08-14');
    $estimation = Estimation::factory()->create(['status' => EstimationStatus::Pending, 'expiration_date' => '2026-09-01']);

    expect($estimation->isExpired)->toBeFalse();

    Carbon::setTestNow();
});

test('isExpired is true for a pending estimation with a past expiration date', function () {
    Carbon::setTestNow('2026-08-14');
    $estimation = Estimation::factory()->create(['status' => EstimationStatus::Pending, 'expiration_date' => '2026-08-01']);

    expect($estimation->isExpired)->toBeTrue();

    Carbon::setTestNow();
});

test('isExpired is false for an accepted estimation even with a past expiration date', function () {
    Carbon::setTestNow('2026-08-14');
    $estimation = Estimation::factory()->create(['status' => EstimationStatus::Accepted, 'expiration_date' => '2026-08-01']);

    expect($estimation->isExpired)->toBeFalse();

    Carbon::setTestNow();
});
