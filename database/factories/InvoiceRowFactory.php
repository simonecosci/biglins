<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceRow>
 */
class InvoiceRowFactory extends Factory
{
    protected $model = InvoiceRow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'description' => fake()->sentence(4),
            'quantity' => fake()->numberBetween(1, 5),
            'price' => fake()->randomFloat(2, 10, 1000),
            'vat_rate' => fake()->randomElement([7, 0]),
        ];
    }

    public function subscription(): static
    {
        return $this->state(fn (): array => [
            'expiration_date' => fake()->dateTimeBetween('-60 days', '+120 days')->format('Y-m-d'),
            'subscription_status' => SubscriptionStatus::Active,
        ]);
    }
}
