<?php

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;

test('product factory creates a product', function () {
    $product = Product::factory()->create();

    expect($product->id)->toBeString();
    expect(strlen($product->id))->toBe(36);
    expect($product->type)->toBeInstanceOf(ProductType::class);
});

test('product belongs to a company', function () {
    $company = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $company->id]);

    expect($product->company)->toBeInstanceOf(Company::class);
    expect($product->company->id)->toBe($company->id);
});

test('a company can have many products', function () {
    $company = Company::factory()->create();
    Product::factory()->count(2)->create(['company_id' => $company->id]);

    expect($company->fresh()->products)->toHaveCount(2);
});

test('a product requires a company_id at the database level', function () {
    expect(fn () => Product::factory()->create(['company_id' => null]))
        ->toThrow(QueryException::class);
});

test('guests are redirected to the login page when visiting products', function () {
    $this->get(route('products.index'))->assertRedirect(route('login'));
});

test('products index page can be rendered', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Product::factory()->count(3)->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('products.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('products/Index'));
});

test('products index only lists products for the current company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Product::factory()->count(2)->create(['company_id' => $company->id]);
    Product::factory()->count(3)->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('products.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('products.data', 2));
});

test('products index renders with an empty state when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('products.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('products.data', 0));
});

test('products index can be searched as json for the invoice picker', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Product::factory()->create(['company_id' => $company->id, 'code' => 'SKU-1', 'description' => 'Blue widget']);
    Product::factory()->create(['company_id' => $company->id, 'code' => 'SKU-2', 'description' => 'Red widget']);
    Product::factory()->create(['company_id' => $company->id, 'code' => 'SKU-3', 'description' => 'Consulting hour']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->getJson(route('products.index', ['search' => 'widget']));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect(collect($response->json('data'))->pluck('code')->sort()->values()->all())
        ->toBe(['SKU-1', 'SKU-2']);
});

test('the product picker json response does not include products from another company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Product::factory()->create(['company_id' => $company->id, 'code' => 'SKU-1', 'description' => 'Blue widget']);
    Product::factory()->create(['company_id' => $otherCompany->id, 'code' => 'SKU-2', 'description' => 'Blue widget']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->getJson(route('products.index', ['search' => 'widget']));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.code'))->toBe('SKU-1');
});

test('products index json response is paginated', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Product::factory()->count(20)->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->getJson(route('products.index'));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(15);
    expect($response->json('last_page'))->toBe(2);
});

test('product can be created without a code', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'type' => ProductType::Product->value,
        'description' => 'Widget',
        'price' => 9.99,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('products.index'));
    $product = Product::query()->where('description', 'Widget')->firstOrFail();
    expect($product->company_id)->toBe($company->id);
});

test('product create page redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('products.create'));

    $response->assertRedirect(route('companies.create'));
});

test('product store redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('products.store'), [
        'type' => ProductType::Product->value,
        'description' => 'Widget',
        'price' => 9.99,
    ]);

    $response->assertRedirect(route('companies.create'));
    expect(Product::query()->where('description', 'Widget')->exists())->toBeFalse();
});

test('product store ignores a company_id sent in the payload and uses the current company instead', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'company_id' => $otherCompany->id,
        'type' => ProductType::Product->value,
        'description' => 'Widget',
        'price' => 9.99,
    ]);

    $product = Product::query()->where('description', 'Widget')->firstOrFail();
    expect($product->company_id)->toBe($company->id);
});

test('product description is required', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'type' => ProductType::Product->value,
        'description' => '',
        'price' => 9.99,
    ]);

    $response->assertSessionHasErrors('description');
});

test('product type must be a valid enum value', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'type' => 'invalid',
        'description' => 'Widget',
        'price' => 9.99,
    ]);

    $response->assertSessionHasErrors('type');
});

test('product price is required', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'type' => ProductType::Product->value,
        'description' => 'Widget',
        'price' => '',
    ]);

    $response->assertSessionHasErrors('price');
});

test('product code must be unique when present', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Product::factory()->create(['company_id' => $company->id, 'code' => 'SKU-1']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'code' => 'SKU-1',
        'type' => ProductType::Product->value,
        'description' => 'Widget',
        'price' => 9.99,
    ]);

    $response->assertSessionHasErrors('code');
});

test('product can be created with a code', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('products.store'), [
        'code' => 'SKU-2',
        'type' => ProductType::Service->value,
        'description' => 'Consulting',
        'price' => 100,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('products.index'));
    expect(Product::query()->where('code', 'SKU-2')->first()->type)->toBe(ProductType::Service);
});

test('product can be updated', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $company->id, 'description' => 'Old description']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('products.update', $product), [
        'code' => $product->code,
        'type' => $product->type->value,
        'description' => 'New description',
        'price' => $product->price,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('products.index'));
    expect($product->fresh()->description)->toBe('New description');
});

test('product update keeps its own code as valid', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $company->id, 'code' => 'SKU-3']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('products.update', $product), [
        'code' => 'SKU-3',
        'type' => $product->type->value,
        'description' => $product->description,
        'price' => $product->price,
    ]);

    $response->assertSessionHasNoErrors();
});

test('product can be deleted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('products.destroy', $product));

    $response->assertRedirect(route('products.index'));
    expect(Product::query()->find($product->id))->toBeNull();
});

test('viewing the edit page of a product from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('products.edit', $product));

    $response->assertForbidden();
});

test('updating a product from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('products.update', $product), [
        'type' => $product->type->value,
        'description' => 'Hacked description',
        'price' => $product->price,
    ]);

    $response->assertForbidden();
    expect($product->fresh()->description)->not->toBe('Hacked description');
});

test('deleting a product from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('products.destroy', $product));

    $response->assertForbidden();
    expect(Product::query()->find($product->id))->not->toBeNull();
});
