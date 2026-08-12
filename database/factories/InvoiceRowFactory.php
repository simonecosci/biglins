<?php

namespace Database\Factories;

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
            'price' => fake()->randomFloat(2, 10, 1000),
            'vat_rate' => fake()->randomElement([7, 0]),
        ];
    }
}
