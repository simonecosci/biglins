<?php

use App\Enums\EstimationStatus;
use App\Models\Company;
use App\Models\Estimation;
use App\Models\EstimationRow;
use App\Models\Invoice;
use App\Models\User;

test('guests are redirected to the login page when converting an estimation', function () {
    $estimation = Estimation::factory()->create();

    $this->post(route('estimations.convert-to-invoice', $estimation))->assertRedirect(route('login'));
});

test('an accepted estimation can be converted to an invoice', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Accepted, 'language' => 'it']);
    EstimationRow::factory()->create(['estimation_id' => $estimation->id, 'description' => 'Consulting', 'quantity' => 2, 'price' => 100, 'vat_rate' => 22]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.convert-to-invoice', $estimation));

    $estimation->refresh();
    expect($estimation->invoice_id)->not->toBeNull();

    $invoice = Invoice::query()->findOrFail($estimation->invoice_id);
    expect($invoice->customer_id)->toBe($estimation->customer_id);
    expect($invoice->company_id)->toBe($company->id);
    expect($invoice->language)->toBe('it');
    expect($invoice->paid)->toBeFalse();
    expect($invoice->rows)->toHaveCount(1);
    expect($invoice->rows->first()->description)->toBe('Consulting');
    expect((float) $invoice->rows->first()->quantity)->toEqual(2.0);

    $response->assertRedirect(route('invoices.edit', $invoice));
});

test('a pending estimation cannot be converted to an invoice', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Pending]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.convert-to-invoice', $estimation));

    expect($estimation->fresh()->invoice_id)->toBeNull();
});

test('an already converted estimation cannot be converted again', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Accepted, 'invoice_id' => $invoice->id]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.convert-to-invoice', $estimation));

    expect(Invoice::count())->toBe(1);
});

test('converting an estimation from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $otherCompany->id, 'status' => EstimationStatus::Accepted]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.convert-to-invoice', $estimation));

    $response->assertForbidden();
    expect($estimation->fresh()->invoice_id)->toBeNull();
});
