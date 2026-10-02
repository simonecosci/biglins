<?php

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->country(),
            'iso_code' => null,
        ];
    }

    public function italy(): static
    {
        return $this->state(fn (): array => ['name' => 'Italy', 'iso_code' => 'IT']);
    }

    public function spain(): static
    {
        return $this->state(fn (): array => ['name' => 'Spain', 'iso_code' => 'ES']);
    }
}
