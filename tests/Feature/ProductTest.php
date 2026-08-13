<?php

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\User;

test('product factory creates a product', function () {
    $product = Product::factory()->create();

    expect($product->id)->toBeString();
    expect(strlen($product->id))->toBe(36);
    expect($product->type)->toBeInstanceOf(ProductType::class);
});

test('guests are redirected to the login page when visiting products', function () {
    $this->get(route('products.index'))->assertRedirect(route('login'));
});

test('products index page can be rendered', function () {
    $user = User::factory()->create();
    Product::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('products.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('products/Index'));
});

test('product can be created without a code', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('products.store'), [
        'type' => ProductType::Product->value,
        'description' => 'Widget',
        'price' => 9.99,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('products.index'));
    expect(Product::query()->where('description', 'Widget')->exists())->toBeTrue();
});

test('product description is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('products.store'), [
        'type' => ProductType::Product->value,
        'description' => '',
        'price' => 9.99,
    ]);

    $response->assertSessionHasErrors('description');
});

test('product type must be a valid enum value', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('products.store'), [
        'type' => 'invalid',
        'description' => 'Widget',
        'price' => 9.99,
    ]);

    $response->assertSessionHasErrors('type');
});

test('product price is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('products.store'), [
        'type' => ProductType::Product->value,
        'description' => 'Widget',
        'price' => '',
    ]);

    $response->assertSessionHasErrors('price');
});

test('product code must be unique when present', function () {
    $user = User::factory()->create();
    Product::factory()->create(['code' => 'SKU-1']);

    $response = $this->actingAs($user)->post(route('products.store'), [
        'code' => 'SKU-1',
        'type' => ProductType::Product->value,
        'description' => 'Widget',
        'price' => 9.99,
    ]);

    $response->assertSessionHasErrors('code');
});

test('product can be created with a code', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('products.store'), [
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
    $product = Product::factory()->create(['description' => 'Old description']);

    $response = $this->actingAs($user)->put(route('products.update', $product), [
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
    $product = Product::factory()->create(['code' => 'SKU-3']);

    $response = $this->actingAs($user)->put(route('products.update', $product), [
        'code' => 'SKU-3',
        'type' => $product->type->value,
        'description' => $product->description,
        'price' => $product->price,
    ]);

    $response->assertSessionHasNoErrors();
});

test('product can be deleted', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $response = $this->actingAs($user)->delete(route('products.destroy', $product));

    $response->assertRedirect(route('products.index'));
    expect(Product::query()->find($product->id))->toBeNull();
});
