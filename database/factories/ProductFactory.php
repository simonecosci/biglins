<?php

namespace Database\Factories;

use App\Enums\ProductDuration;
use App\Enums\ProductType;
use App\Models\Company;
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
            'duration' => null,
            'description' => fake()->words(3, true),
            'price' => fake()->randomFloat(2, 1, 1000),
            'company_id' => Company::factory(),
        ];
    }

    /**
     * Indicate that the product has a recurring duration.
     */
    public function withDuration(?ProductDuration $duration = null): static
    {
        return $this->state(fn () => [
            'duration' => $duration ?? fake()->randomElement(ProductDuration::cases()),
        ]);
    }
}
