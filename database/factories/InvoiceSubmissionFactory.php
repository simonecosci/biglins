<?php

namespace Database\Factories;

use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceSubmission>
 */
class InvoiceSubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory()->issued(),
            'e_invoicing_integration_id' => fn (array $attributes) => EInvoicingIntegration::query()->firstOrCreate(
                ['company_id' => Invoice::query()->whereKey($attributes['invoice_id'])->value('company_id')],
                ['driver' => EInvoicingDriver::Fake, 'environment' => EInvoicingEnvironment::Sandbox, 'credentials' => [], 'is_active' => true],
            )->id,
            'driver' => EInvoicingDriver::Fake,
            'status' => SubmissionStatus::Submitted,
            'external_id' => 'fake-'.fake()->uuid(),
            'submitted_at' => now(),
        ];
    }
}
