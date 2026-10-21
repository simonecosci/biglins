<?php

namespace Database\Factories;

use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use App\Models\Company;
use App\Models\EInvoicingIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EInvoicingIntegration>
 */
class EInvoicingIntegrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'driver' => EInvoicingDriver::Fake,
            'environment' => EInvoicingEnvironment::Sandbox,
            'credentials' => [],
            'is_active' => true,
        ];
    }
}
