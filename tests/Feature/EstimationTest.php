<?php

use App\Enums\EstimationStatus;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimation;
use App\Models\EstimationRow;
use App\Models\Invoice;
use App\Models\User;
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

test('estimation has many rows and rows are deleted when the estimation is deleted', function () {
    $estimation = Estimation::factory()->create();
    EstimationRow::factory()->count(2)->create(['estimation_id' => $estimation->id]);

    expect($estimation->rows)->toHaveCount(2);

    $estimation->delete();

    expect(EstimationRow::query()->where('estimation_id', $estimation->id)->count())->toBe(0);
});

test('estimation total accessors sum its rows', function () {
    $estimation = Estimation::factory()->create();
    EstimationRow::factory()->create(['estimation_id' => $estimation->id, 'quantity' => 1, 'price' => 100, 'vat_rate' => 22]);
    EstimationRow::factory()->create(['estimation_id' => $estimation->id, 'quantity' => 1, 'price' => 50, 'vat_rate' => 10]);

    expect((float) $estimation->subtotal)->toEqual(150.0);
    expect((float) $estimation->vat_total)->toEqual(27.0);
    expect((float) $estimation->total)->toEqual(177.0);
});

test('estimation has many attachments and attachments are deleted when the estimation is deleted', function () {
    $estimation = Estimation::factory()->create();
    Attachment::factory()->count(2)->create([
        'attachable_type' => Estimation::class,
        'attachable_id' => $estimation->id,
    ]);

    expect($estimation->attachments)->toHaveCount(2);

    $estimation->delete();

    expect(Attachment::query()->where('attachable_id', $estimation->id)->count())->toBe(0);
});

test('guests are redirected to the login page when visiting estimations', function () {
    $this->get(route('estimations.index'))->assertRedirect(route('login'));
});

test('estimations index page can be rendered', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Estimation::factory()->count(3)->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('estimations.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('estimations/Index'));
});

test('estimations index only lists estimations for the current company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Estimation::factory()->count(2)->create(['company_id' => $company->id]);
    Estimation::factory()->count(3)->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('estimations.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('estimations.data', 2));
});

test('estimation create page redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('estimations.create'));

    $response->assertRedirect(route('companies.create'));
});

test('estimation can be created with rows', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.store'), [
        'customer_id' => $customer->id,
        'estimation_date' => '2026-08-14',
        'expiration_date' => '2026-09-14',
        'language' => 'en',
        'body' => 'A commercial proposal.',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 2, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('estimations.index'));

    $estimation = Estimation::query()->where('customer_id', $customer->id)->firstOrFail();
    expect($estimation->rows)->toHaveCount(1);
    expect($estimation->number)->not->toBeNull();
    expect($estimation->status)->toBe(EstimationStatus::Pending);
    expect($estimation->company_id)->toBe($company->id);
});

test('estimation customer_id must belong to the current company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $foreignCustomer = Customer::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.store'), [
        'customer_id' => $foreignCustomer->id,
        'estimation_date' => '2026-08-14',
        'expiration_date' => '2026-09-14',
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('customer_id');
});

test('estimation expiration_date must not be before estimation_date', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.store'), [
        'customer_id' => $customer->id,
        'estimation_date' => '2026-08-14',
        'expiration_date' => '2026-08-01',
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('expiration_date');
});

test('estimation edit page can be rendered', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('estimations.edit', $estimation));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('estimations/Edit')
        ->has('estimation')
        ->has('customers')
    );
});

test('viewing the edit page of an estimation from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('estimations.edit', $estimation));

    $response->assertForbidden();
});

test('updating an estimation syncs its rows and status', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Pending]);
    $keepRow = EstimationRow::factory()->create(['estimation_id' => $estimation->id, 'description' => 'Keep me']);
    $removeRow = EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('estimations.update', $estimation), [
        'estimation_date' => $estimation->estimation_date->format('Y-m-d'),
        'expiration_date' => $estimation->expiration_date->format('Y-m-d'),
        'language' => $estimation->language,
        'status' => 'accepted',
        'body' => 'Updated proposal',
        'rows' => [
            ['id' => $keepRow->id, 'description' => 'Updated description', 'quantity' => 3, 'price' => 20, 'vat_rate' => 10],
            ['description' => 'New row', 'quantity' => 1, 'price' => 30, 'vat_rate' => 4],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('estimations.index'));

    $estimation->refresh();
    expect($estimation->status)->toBe(EstimationStatus::Accepted);
    expect($estimation->body)->toBe('Updated proposal');
    expect($estimation->rows)->toHaveCount(2);
    expect(EstimationRow::query()->find($removeRow->id))->toBeNull();
    expect($keepRow->fresh()->description)->toBe('Updated description');
});

test('updating an estimation ignores a client-supplied customer_id', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $originalCustomer = Customer::factory()->create(['company_id' => $company->id]);
    $otherCustomer = Customer::factory()->create(['company_id' => $company->id]);
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'customer_id' => $originalCustomer->id]);
    $row = EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('estimations.update', $estimation), [
        'customer_id' => $otherCustomer->id,
        'estimation_date' => $estimation->estimation_date->format('Y-m-d'),
        'expiration_date' => $estimation->expiration_date->format('Y-m-d'),
        'language' => $estimation->language,
        'status' => 'pending',
        'rows' => [
            ['id' => $row->id, 'description' => $row->description, 'quantity' => $row->quantity, 'price' => $row->price, 'vat_rate' => $row->vat_rate],
        ],
    ]);

    expect($estimation->fresh()->customer_id)->toBe($originalCustomer->id);
});

test('an estimation already converted to an invoice cannot be updated', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Accepted, 'invoice_id' => $invoice->id, 'body' => 'Original']);
    $row = EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('estimations.update', $estimation), [
        'estimation_date' => $estimation->estimation_date->format('Y-m-d'),
        'expiration_date' => $estimation->expiration_date->format('Y-m-d'),
        'language' => $estimation->language,
        'status' => 'accepted',
        'body' => 'Hacked',
        'rows' => [
            ['id' => $row->id, 'description' => $row->description, 'quantity' => $row->quantity, 'price' => $row->price, 'vat_rate' => $row->vat_rate],
        ],
    ]);

    expect($estimation->fresh()->body)->toBe('Original');
});

test('estimation can be deleted when pending', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Pending]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('estimations.destroy', $estimation));

    $response->assertRedirect(route('estimations.index'));
    expect(Estimation::query()->find($estimation->id))->toBeNull();
});

test('an accepted estimation cannot be deleted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Accepted]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('estimations.destroy', $estimation));

    expect(Estimation::query()->find($estimation->id))->not->toBeNull();
});

test('a converted estimation cannot be deleted even if rejected', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'status' => EstimationStatus::Rejected, 'invoice_id' => $invoice->id]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('estimations.destroy', $estimation));

    expect(Estimation::query()->find($estimation->id))->not->toBeNull();
});
