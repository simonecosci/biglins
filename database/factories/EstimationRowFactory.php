<?php

namespace Database\Factories;

use App\Models\Estimation;
use App\Models\EstimationRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EstimationRow>
 */
class EstimationRowFactory extends Factory
{
    protected $model = EstimationRow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estimation_id' => Estimation::factory(),
            'description' => fake()->sentence(4),
            'quantity' => fake()->numberBetween(1, 5),
            'price' => fake()->randomFloat(2, 10, 1000),
            'vat_rate' => fake()->randomElement([22, 10, 0]),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
