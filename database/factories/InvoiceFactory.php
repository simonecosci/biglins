<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => null,
            'invoice_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'paid' => fake()->boolean(),
            'company_id' => Company::factory(),
            'customer_id' => fn (array $attributes) => Customer::factory()->create(['company_id' => $attributes['company_id']])->id,
            'note' => fake()->optional()->sentence(),
            'language' => fake()->randomElement(['it', 'en', 'es']),
        ];
    }
}
