<?php

namespace Database\Factories;

use App\Enums\EstimationStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Estimation>
 */
class EstimationFactory extends Factory
{
    protected $model = Estimation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => null,
            'company_id' => Company::factory(),
            'customer_id' => fn (array $attributes) => Customer::factory()->create(['company_id' => $attributes['company_id']])->id,
            'estimation_date' => fake()->dateTimeBetween('-2 months', 'now')->format('Y-m-d'),
            'expiration_date' => fake()->dateTimeBetween('now', '+2 months')->format('Y-m-d'),
            'language' => fake()->randomElement(['it', 'en', 'es']),
            'body' => fake()->optional()->paragraphs(3, true),
            'status' => EstimationStatus::Pending,
        ];
    }
}
