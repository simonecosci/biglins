<?php

namespace Database\Factories;

use App\Models\InvoiceSubmission;
use App\Models\InvoiceSubmissionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceSubmissionEvent>
 */
class InvoiceSubmissionEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => InvoiceSubmission::factory(),
            'type' => 'status_change',
            'provider_event_id' => 'evt-'.fake()->uuid(),
            'payload' => [],
            'received_at' => now(),
        ];
    }
}
