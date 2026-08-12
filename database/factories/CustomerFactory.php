<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'address' => fake()->streetAddress(),
            'zip' => fake()->postcode(),
            'city' => fake()->city(),
            'country_id' => Country::factory(),
            'state' => fake()->state(),
            'email' => fake()->unique()->companyEmail(),
            'web' => fake()->url(),
            'phone' => fake()->phoneNumber(),
            'nif' => fake()->numerify('########'),
        ];
    }
}
