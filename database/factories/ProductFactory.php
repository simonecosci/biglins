<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('PRD-####'),
            'type' => fake()->randomElement(ProductType::cases()),
            'description' => fake()->words(3, true),
            'price' => fake()->randomFloat(2, 1, 1000),
        ];
    }
}
