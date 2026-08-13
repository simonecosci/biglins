<?php

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

test('invoice factory creates an invoice with a uuid primary key', function () {
    $invoice = Invoice::factory()->create();

    expect($invoice->id)->toBeString();
    expect(strlen($invoice->id))->toBe(36);
    expect($invoice->customer)->toBeInstanceOf(Customer::class);
    expect($invoice->company)->toBeInstanceOf(Company::class);
});

test('first invoice of the year is numbered 0001', function () {
    Carbon::setTestNow('2026-01-15');
    $company = Company::factory()->create();

    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'number' => null]);

    expect($invoice->number)->toBe('2026-0001');

    Carbon::setTestNow();
});

test('subsequent invoices in the same year increment the sequence', function () {
    Carbon::setTestNow('2026-01-15');
    $company = Company::factory()->create();

    Invoice::factory()->create(['company_id' => $company->id, 'number' => null]);
    $second = Invoice::factory()->create(['company_id' => $company->id, 'number' => null]);
    $third = Invoice::factory()->create(['company_id' => $company->id, 'number' => null]);

    expect($second->number)->toBe('2026-0002');
    expect($third->number)->toBe('2026-0003');

    Carbon::setTestNow();
});

test('a new calendar year resets the sequence to 0001', function () {
    $company = Company::factory()->create();

    Carbon::setTestNow('2026-12-31');
    Invoice::factory()->create(['company_id' => $company->id, 'number' => null]);

    Carbon::setTestNow('2027-01-01');
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'number' => null]);

    expect($invoice->number)->toBe('2027-0001');

    Carbon::setTestNow();
});

test('each company has its own independent numbering sequence', function () {
    Carbon::setTestNow('2026-01-15');
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Invoice::factory()->create(['company_id' => $companyA->id, 'number' => null]);
    $firstForB = Invoice::factory()->create(['company_id' => $companyB->id, 'number' => null]);
    $secondForA = Invoice::factory()->create(['company_id' => $companyA->id, 'number' => null]);

    expect($firstForB->number)->toBe('2026-0001');
    expect($secondForA->number)->toBe('2026-0002');

    Carbon::setTestNow();
});

test('an explicitly provided number is respected instead of being generated', function () {
    $invoice = Invoice::factory()->create(['number' => '2026-9999']);

    expect($invoice->number)->toBe('2026-9999');
});

test('invoice number must be unique per company at the database level', function () {
    $company = Company::factory()->create();
    Invoice::factory()->create(['company_id' => $company->id, 'number' => '2026-0001']);

    expect(fn () => Invoice::factory()->create(['company_id' => $company->id, 'number' => '2026-0001']))
        ->toThrow(QueryException::class);
});

test('the same invoice number can be reused by a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    Invoice::factory()->create(['company_id' => $companyA->id, 'number' => '2026-0001']);

    $invoice = Invoice::factory()->create(['company_id' => $companyB->id, 'number' => '2026-0001']);

    expect($invoice->number)->toBe('2026-0001');
});

test('invoice has many rows and rows are deleted when the invoice is deleted', function () {
    $invoice = Invoice::factory()->create();
    InvoiceRow::factory()->count(2)->create(['invoice_id' => $invoice->id]);

    expect($invoice->rows)->toHaveCount(2);

    $invoice->delete();

    expect(InvoiceRow::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('a customer with invoices cannot be deleted', function () {
    $customer = Customer::factory()->create();
    Invoice::factory()->create(['customer_id' => $customer->id]);

    expect(fn () => $customer->delete())->toThrow(QueryException::class);
});

test('invoice total accessors sum its rows', function () {
    $invoice = Invoice::factory()->create();
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => 100, 'vat_rate' => 22]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => 50, 'vat_rate' => 10]);

    expect((float) $invoice->subtotal)->toEqual(150.0);
    expect((float) $invoice->vat_total)->toEqual(27.0);
    expect((float) $invoice->total)->toEqual(177.0);
});

test('invoice total accessors multiply price by quantity per row', function () {
    $invoice = Invoice::factory()->create();
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 2, 'price' => 100, 'vat_rate' => 22]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 3, 'price' => 10, 'vat_rate' => 0]);

    expect((float) $invoice->subtotal)->toEqual(230.0);
    expect((float) $invoice->vat_total)->toEqual(44.0);
    expect((float) $invoice->total)->toEqual(274.0);
});

test('invoice row total accessor adds vat to the price', function () {
    $row = InvoiceRow::factory()->create(['quantity' => 1, 'price' => 100, 'vat_rate' => 22]);

    expect((float) $row->total)->toEqual(122.0);
});

test('invoice row total accessor multiplies price by quantity before applying vat', function () {
    $row = InvoiceRow::factory()->create(['quantity' => 2, 'price' => 100, 'vat_rate' => 22]);

    expect((float) $row->total)->toEqual(244.0);
});

use App\Models\User;
use Illuminate\Support\Str;

test('guests are redirected to the login page when visiting invoices', function () {
    $this->get(route('invoices.index'))->assertRedirect(route('login'));
});

test('invoices index page can be rendered', function () {
    $user = User::factory()->create();
    Invoice::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('invoices.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('invoices/Index'));
});

test('invoices index only lists invoices for the current company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Invoice::factory()->count(2)->create(['company_id' => $company->id]);
    Invoice::factory()->count(3)->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('invoices.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('invoices.data', 2));
});

test('invoices index renders with an empty state when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('invoices.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('invoices.data', 0));
});

test('invoice create page can be rendered', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('invoices.create'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->has('nextNumber')
    );
});

test('invoice create page redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('invoices.create'));

    $response->assertRedirect(route('companies.create'));
});

test('invoice edit page can be rendered', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('invoices.edit', $invoice));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Edit')
        ->has('invoice')
        ->has('customers')
    );
});

test('viewing the edit page of an invoice from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('invoices.edit', $invoice));

    $response->assertForbidden();
});

test('invoice can be created with rows', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'paid' => false,
        'customer_id' => $customer->id,
        'note' => 'Test note',
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 2, 'price' => 100, 'vat_rate' => 22],
            ['description' => 'Hosting', 'quantity' => 1, 'price' => 50, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('invoices.index'));

    $invoice = Invoice::query()->where('customer_id', $customer->id)->firstOrFail();
    expect($invoice->rows)->toHaveCount(2);
    expect($invoice->number)->not->toBeNull();
    expect($invoice->language)->toBe('en');
    expect($invoice->company_id)->toBe($company->id);
    expect($invoice->rows->firstWhere('description', 'Consulting')->quantity)->toEqual(2.0);
});

test('invoice store redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();

    $response = $this->actingAs($user)->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertRedirect(route('companies.create'));
    expect(Invoice::query()->where('customer_id', $customer->id)->exists())->toBeFalse();
});

test('invoice store ignores a company_id sent in the payload and uses the current company instead', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'company_id' => $otherCompany->id,
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $invoice = Invoice::query()->where('customer_id', $customer->id)->firstOrFail();
    expect($invoice->company_id)->toBe($company->id);
});

test('a number already used by another company passes validation', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Invoice::factory()->create(['company_id' => $otherCompany->id, 'number' => '2026-0050']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'number' => '2026-0050',
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 1, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('invoices.index'));
});

test('invoice requires at least one row', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'rows' => [],
    ]);

    $response->assertSessionHasErrors('rows');
});

test('invoice row requires a quantity', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('rows.0.quantity');
});

test('invoice row quantity must be greater than zero', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'en',
        'rows' => [
            ['description' => 'Consulting', 'quantity' => 0, 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('rows.0.quantity');
});

test('invoice customer_id must reference an existing customer', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => (string) Str::uuid(),
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('customer_id');
});

test('invoice number can be set explicitly and must be unique', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();
    Invoice::factory()->create(['company_id' => $company->id, 'number' => '2026-0050']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'number' => '2026-0050',
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('number');
});

test('updating an invoice syncs its rows: adds, updates and removes', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    $keepRow = InvoiceRow::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Keep me',
        'quantity' => 1,
        'price' => 10,
        'vat_rate' => 22,
    ]);
    $removeRow = InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('invoices.update', $invoice), [
        'number' => $invoice->number,
        'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
        'paid' => true,
        'customer_id' => $invoice->customer_id,
        'language' => $invoice->language,
        'rows' => [
            ['id' => $keepRow->id, 'description' => 'Updated description', 'quantity' => 3, 'price' => 20, 'vat_rate' => 10],
            ['description' => 'New row', 'quantity' => 1, 'price' => 30, 'vat_rate' => 4],
        ],
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('invoices.index'));

    $invoice->refresh();
    expect($invoice->paid)->toBeTrue();
    expect($invoice->rows)->toHaveCount(2);
    expect(InvoiceRow::query()->find($removeRow->id))->toBeNull();
    expect($keepRow->fresh()->description)->toBe('Updated description');
    expect($keepRow->fresh()->quantity)->toEqual(3.0);
});

test('updating an invoice from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id, 'paid' => false]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('invoices.update', $invoice), [
        'number' => $invoice->number,
        'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
        'paid' => true,
        'customer_id' => $invoice->customer_id,
        'language' => $invoice->language,
        'rows' => [
            ['description' => 'Hacked row', 'quantity' => 1, 'price' => 1, 'vat_rate' => 0],
        ],
    ]);

    $response->assertForbidden();
    expect($invoice->fresh()->paid)->toBeFalse();
});

test('invoice can be deleted and its rows are removed', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);
    InvoiceRow::factory()->count(2)->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('invoices.destroy', $invoice));

    $response->assertRedirect(route('invoices.index'));
    expect(Invoice::query()->find($invoice->id))->toBeNull();
    expect(InvoiceRow::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('deleting an invoice from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('invoices.destroy', $invoice));

    $response->assertForbidden();
    expect(Invoice::query()->find($invoice->id))->not->toBeNull();
});

test('invoice factory produces a valid language', function () {
    $invoice = Invoice::factory()->create();

    expect(['it', 'en', 'es'])->toContain($invoice->language);
});

test('invoice requires a language', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => '',
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('language');
});

test('invoice language must be it, en, or es', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.store'), [
        'invoice_date' => '2026-01-15',
        'customer_id' => $customer->id,
        'language' => 'fr',
        'rows' => [
            ['description' => 'Consulting', 'price' => 100, 'vat_rate' => 22],
        ],
    ]);

    $response->assertSessionHasErrors('language');
});

test('guests are redirected to the login page when previewing an invoice', function () {
    $invoice = Invoice::factory()->create();

    $this->get(route('invoices.preview', $invoice))->assertRedirect(route('login'));
});

test('invoice preview renders the invoice as html', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create(['language' => 'en', 'note' => 'Please pay within 30 days']);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id, 'description' => 'Design work']);

    $response = $this->actingAs($user)->get(route('invoices.preview', $invoice));

    $response->assertOk();
    $response->assertSee($invoice->number);
    $response->assertSee($invoice->company->name);
    $response->assertSee($invoice->customer->name);
    $response->assertSee('Design work');
    $response->assertSee('Please pay within 30 days');
});

test('invoice pdf downloads as a pdf file', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create();
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain($invoice->number);
});

test('guests are redirected to the login page when downloading an invoice pdf', function () {
    $invoice = Invoice::factory()->create();

    $this->get(route('invoices.pdf', $invoice))->assertRedirect(route('login'));
});

test('invoice preview renders the italian locale label', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create(['language' => 'it']);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->get(route('invoices.preview', $invoice));

    $response->assertOk();
    $response->assertSee('Fattura');
});

test('invoice pdf download sanitizes slashes in the invoice number', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create(['number' => '2026/0001']);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('2026-0001');
    expect($disposition)->not->toContain('2026/0001');
});

test('invoice create page prefills from a duplicate query param', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $currentCompany = Company::factory()->create();
    $sourceCompany = Company::factory()->create();
    $source = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'company_id' => $sourceCompany->id,
        'note' => 'Source note',
        'language' => 'en',
        'paid' => true,
    ]);
    InvoiceRow::factory()->create([
        'invoice_id' => $source->id,
        'description' => 'Consulting',
        'quantity' => 2,
        'price' => 100,
        'vat_rate' => 22,
    ]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $currentCompany->id])->get(route('invoices.create', ['duplicate' => $source->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate.customer_id', $customer->id)
        ->where('duplicate.note', 'Source note')
        ->where('duplicate.language', 'en')
        ->where('duplicate.rows.0.description', 'Consulting')
        ->where('duplicate.rows.0.quantity', 2)
        ->where('duplicate.rows.0.price', 100)
        ->where('duplicate.rows.0.vat_rate', 22)
        ->missing('duplicate.company_id')
        ->missing('duplicate.paid')
        ->missing('duplicate.rows.0.id')
    );
});

test('invoice create page ignores an invalid duplicate id', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('invoices.create', ['duplicate' => 'not-a-real-id']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate', null)
    );
});

test('invoice create page ignores an array-valued duplicate param', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get('/invoices/create?duplicate[]=x');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate', null)
    );
});

test('a duplicated invoice can be saved as a new invoice', function () {
    $user = User::factory()->create();
    $currentCompany = Company::factory()->create();
    $sourceCompany = Company::factory()->create();
    $source = Invoice::factory()->create(['company_id' => $sourceCompany->id, 'language' => 'en', 'paid' => true]);
    InvoiceRow::factory()->create([
        'invoice_id' => $source->id, 'description' => 'Consulting', 'price' => 100, 'vat_rate' => 22,
    ]);

    $page = $this->actingAs($user)->withSession(['current_company_id' => $currentCompany->id])->get(route('invoices.create', ['duplicate' => $source->id]));
    $duplicate = $page->viewData('page')['props']['duplicate'];

    $this->actingAs($user)->withSession(['current_company_id' => $currentCompany->id])->post(route('invoices.store'), [
        'number' => $duplicate['number'] ?? null,
        'invoice_date' => now()->toDateString(),
        'paid' => false,
        'customer_id' => $duplicate['customer_id'],
        'note' => $duplicate['note'] ?? '',
        'language' => $duplicate['language'],
        'rows' => $duplicate['rows'],
    ])->assertRedirect(route('invoices.index'))->assertSessionHasNoErrors();

    expect(Invoice::count())->toBe(2);
    $new = Invoice::query()->where('id', '!=', $source->id)->firstOrFail();
    expect($new->paid)->toBeFalse()
        ->and($new->rows)->toHaveCount(1)
        ->and($new->company_id)->toBe($currentCompany->id);
    expect($source->fresh()->paid)->toBeTrue(); // source untouched
});

test('invoice create page has no duplicate data without the query param', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('invoices.create'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('invoices/Create')
        ->where('duplicate', null)
    );
});
