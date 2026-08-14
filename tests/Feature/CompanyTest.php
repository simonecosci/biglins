<?php

use App\Models\Company;
use App\Models\Country;
use Illuminate\Support\Facades\File;

/**
 * Logos are written straight into `public/images/companies`, so wipe any file a
 * test left behind — regardless of whether that test passed. The glob skips the
 * tracked `.gitkeep`, which `File::cleanDirectory()` would remove.
 */
afterEach(function () {
    foreach (File::glob(public_path('images/companies/*')) as $file) {
        File::delete($file);
    }
});

test('company factory creates a company belonging to a country', function () {
    $company = Company::factory()->create();

    expect($company->id)->toBeString();
    expect(strlen($company->id))->toBe(36);
    expect($company->country)->toBeInstanceOf(Country::class);
});

test('company can be created without a country', function () {
    $company = Company::factory()->create(['country_id' => null]);

    expect($company->country_id)->toBeNull();
    expect($company->country)->toBeNull();
});

test('company is_default defaults to false and casts to boolean', function () {
    $company = Company::factory()->create();

    expect($company->is_default)->toBeFalse();
});

use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

test('guests are redirected to the login page when visiting companies', function () {
    $this->get(route('companies.index'))->assertRedirect(route('login'));
});

test('companies index page can be rendered', function () {
    $user = User::factory()->create();
    Company::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('companies.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('companies/Index'));
});

test('company can be created with only a name', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Acme Corp',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('companies.index'));
    expect(Company::query()->where('name', 'Acme Corp')->exists())->toBeTrue();
});

test('company name is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => '',
    ]);

    $response->assertSessionHasErrors('name');
});

test('company email must be a valid address when present', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Acme Corp',
        'email' => 'not-an-email',
    ]);

    $response->assertSessionHasErrors('email');
});

test('company country_id must reference an existing country', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Acme Corp',
        'country_id' => (string) Str::uuid(),
    ]);

    $response->assertSessionHasErrors('country_id');
});

test('company can be updated', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->put(route('companies.update', $company), [
        'name' => 'New Name',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('companies.index'));
    expect($company->fresh()->name)->toBe('New Name');
});

test('updating a company without sending is_default preserves its current default status', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['is_default' => true]);

    $response = $this->actingAs($user)->put(route('companies.update', $company), [
        'name' => 'Still Default',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('companies.index'));
    expect($company->fresh()->is_default)->toBeTrue();
});

test('the first company created becomes the default automatically', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'First Company',
    ]);

    $company = Company::query()->where('name', 'First Company')->firstOrFail();
    expect($company->is_default)->toBeTrue();
});

test('marking a company as default unsets the previous default', function () {
    $user = User::factory()->create();
    $first = Company::factory()->create(['is_default' => true]);

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Second Company',
        'is_default' => true,
    ]);

    $response->assertSessionHasNoErrors();
    expect($first->fresh()->is_default)->toBeFalse();
    expect(Company::query()->where('name', 'Second Company')->first()->is_default)->toBeTrue();
});

test('a company without invoices can be deleted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->delete(route('companies.destroy', $company));

    $response->assertRedirect(route('companies.index'));
    expect(Company::query()->find($company->id))->toBeNull();
});

test('a company with invoices cannot be deleted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Invoice::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->delete(route('companies.destroy', $company));

    $response->assertRedirect(route('companies.index'));
    expect(Company::query()->find($company->id))->not->toBeNull();
});

test('a company with products cannot be deleted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Product::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->delete(route('companies.destroy', $company));

    $response->assertRedirect(route('companies.index'));
    expect(Company::query()->find($company->id))->not->toBeNull();
});

use Illuminate\Http\UploadedFile;

test('company logo can be uploaded and is stored in public/images/companies', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Acme Corp',
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('companies.index'));

    $company = Company::query()->where('name', 'Acme Corp')->firstOrFail();
    expect($company->logo)->toBe("images/companies/{$company->id}.png");
    expect(file_exists(public_path($company->logo)))->toBeTrue();
});

/**
 * The Edit page spoofs the method (`POST` + `_method=put`) because browsers can
 * only send a parsable multipart body on `POST`. These tests must send the same
 * shape, otherwise they pass against a request the browser never makes.
 */
test('replacing a company logo deletes the previous file', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user)->post(route('companies.update', $company), [
        '_method' => 'put',
        'name' => $company->name,
        'logo' => UploadedFile::fake()->image('first.jpg'),
    ])->assertSessionHasNoErrors();

    $firstPath = public_path($company->fresh()->logo);
    expect(file_exists($firstPath))->toBeTrue();

    $this->actingAs($user)->post(route('companies.update', $company), [
        '_method' => 'put',
        'name' => $company->name,
        'logo' => UploadedFile::fake()->image('second.png'),
    ])->assertSessionHasNoErrors();

    $company->refresh();
    expect(file_exists($firstPath))->toBeFalse();
    expect(file_exists(public_path($company->logo)))->toBeTrue();
    expect($company->logo)->toBe("images/companies/{$company->id}.png");
});

test('a company logo can be removed without uploading a new one', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user)->post(route('companies.update', $company), [
        '_method' => 'put',
        'name' => $company->name,
        'logo' => UploadedFile::fake()->image('logo.png'),
    ])->assertSessionHasNoErrors();
    $logoPath = public_path($company->fresh()->logo);
    expect(file_exists($logoPath))->toBeTrue();

    $response = $this->actingAs($user)->put(route('companies.update', $company), [
        'name' => $company->name,
        'remove_logo' => true,
    ]);

    $response->assertSessionHasNoErrors();
    expect($company->fresh()->logo)->toBeNull();
    expect(file_exists($logoPath))->toBeFalse();
});

test('company logo must be an image', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Acme Corp',
        'logo' => UploadedFile::fake()->create('not-an-image.pdf', 100),
    ]);

    $response->assertSessionHasErrors('logo');
});

test('an svg company logo is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Acme Corp',
        'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
    ]);

    $response->assertSessionHasErrors('logo');
    expect(Company::query()->where('name', 'Acme Corp')->exists())->toBeFalse();
});

test('a company logo can be replaced through a spoofed multipart PUT from the edit page', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.update', $company), [
        '_method' => 'put',
        'name' => 'Renamed Corp',
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('companies.index'));

    $company->refresh();
    expect($company->name)->toBe('Renamed Corp');
    expect($company->logo)->toBe("images/companies/{$company->id}.png");
    expect(file_exists(public_path($company->logo)))->toBeTrue();
});

/**
 * Without a `File` in the payload Inertia sends a JSON body instead of
 * multipart, so the spoofed method has to survive that shape too (it does:
 * `Request::createFromBase()` points the POST bag at the decoded JSON).
 */
test('the spoofed PUT from the edit page also works when no file is attached', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create();
    $company = Company::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->postJson(route('companies.update', $company), [
        '_method' => 'put',
        'name' => 'New Name',
        'tax_id' => 'X123',
        'address' => 'Main Street 1',
        'zip' => '08001',
        'city' => 'Barcelona',
        'country_id' => $country->id,
        'email' => 'billing@example.com',
        'phone' => '+34 600 000 000',
        'iban' => 'ES9121000418450200051332',
        'is_default' => false,
        'remove_logo' => false,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('companies.index'));

    $company->refresh();
    expect($company->name)->toBe('New Name');
    expect($company->country_id)->toBe($country->id);
    expect($company->city)->toBe('Barcelona');
});

test('company can be created with the real frontend payload shape (blank optional fields as empty strings)', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('companies.store'), [
        'name' => 'Acme Corp',
        'tax_id' => '',
        'address' => '',
        'zip' => '',
        'city' => '',
        'country_id' => '',
        'email' => '',
        'phone' => '',
        'iban' => '',
        'is_default' => false,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('companies.index'));

    $company = Company::query()->where('name', 'Acme Corp')->firstOrFail();
    expect($company->country_id)->toBeNull();
    expect($company->tax_id)->toBeNull();
    expect($company->address)->toBeNull();
    expect($company->zip)->toBeNull();
    expect($company->city)->toBeNull();
    expect($company->email)->toBeNull();
    expect($company->phone)->toBeNull();
    expect($company->iban)->toBeNull();
});

test('company can be updated with the real frontend payload shape (blank optional fields as empty strings)', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['city' => 'Madrid']);

    $response = $this->actingAs($user)->post(route('companies.update', $company), [
        '_method' => 'put',
        'name' => 'Acme Corp',
        'tax_id' => '',
        'address' => '',
        'zip' => '',
        'city' => '',
        'country_id' => '',
        'email' => '',
        'phone' => '',
        'iban' => '',
        'is_default' => false,
        'remove_logo' => false,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('companies.index'));

    $company->refresh();
    expect($company->country_id)->toBeNull();
    expect($company->tax_id)->toBeNull();
    expect($company->city)->toBeNull();
});

test('updating a company to be the default unsets the previous default', function () {
    $user = User::factory()->create();
    $previousDefault = Company::factory()->create(['is_default' => true]);
    $company = Company::factory()->create(['is_default' => false]);

    $response = $this->actingAs($user)->put(route('companies.update', $company), [
        'name' => $company->name,
        'is_default' => true,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('companies.index'));
    expect($company->fresh()->is_default)->toBeTrue();
    expect($previousDefault->fresh()->is_default)->toBeFalse();
});
