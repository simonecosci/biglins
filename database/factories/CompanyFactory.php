<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'vat_number' => fake()->numerify('###########'),
            'tax_code' => null,
            'province' => null,
            'fiscal_details' => null,
            'address' => fake()->streetAddress(),
            'zip' => fake()->postcode(),
            'city' => fake()->city(),
            'country_id' => Country::factory(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'iban' => 'ES'.fake()->numerify('##################'),
            'logo' => null,
            'is_default' => false,
        ];
    }
}
