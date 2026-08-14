<?php

use App\Enums\ExpirationUrgency;
use App\Enums\SubscriptionStatus;
use App\Models\InvoiceRow;
use Illuminate\Support\Carbon;

test('scopeSubscriptions only returns rows with an expiration date and active status', function () {
    $active = InvoiceRow::factory()->create([
        'expiration_date' => '2026-09-01',
        'subscription_status' => SubscriptionStatus::Active,
    ]);
    InvoiceRow::factory()->create(['expiration_date' => null]);
    InvoiceRow::factory()->create([
        'expiration_date' => '2026-09-01',
        'subscription_status' => SubscriptionStatus::Cancelled,
    ]);

    $result = InvoiceRow::query()->subscriptions()->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($active->id);
});

test('expiration_urgency is null when there is no expiration date', function () {
    $row = InvoiceRow::factory()->create(['expiration_date' => null]);

    expect($row->expiration_urgency)->toBeNull();
});

test('expiration_urgency is expired for a past date', function () {
    Carbon::setTestNow('2026-08-13');
    $row = InvoiceRow::factory()->create(['expiration_date' => '2026-08-01']);

    expect($row->expiration_urgency)->toBe(ExpirationUrgency::Expired);

    Carbon::setTestNow();
});

test('expiration_urgency is expiring_soon within the next 30 days, boundaries included', function () {
    Carbon::setTestNow('2026-08-13');

    $today = InvoiceRow::factory()->create(['expiration_date' => '2026-08-13']);
    $boundary = InvoiceRow::factory()->create(['expiration_date' => '2026-09-12']);

    expect($today->expiration_urgency)->toBe(ExpirationUrgency::ExpiringSoon);
    expect($boundary->expiration_urgency)->toBe(ExpirationUrgency::ExpiringSoon);

    Carbon::setTestNow();
});

test('expiration_urgency is upcoming beyond 30 days', function () {
    Carbon::setTestNow('2026-08-13');
    $row = InvoiceRow::factory()->create(['expiration_date' => '2026-09-13']);

    expect($row->expiration_urgency)->toBe(ExpirationUrgency::Upcoming);

    Carbon::setTestNow();
});

test('subscription factory state produces a row with an expiration date', function () {
    $row = InvoiceRow::factory()->subscription()->create();

    expect($row->expiration_date)->not->toBeNull();
    expect($row->subscription_status)->toBe(SubscriptionStatus::Active);
});
