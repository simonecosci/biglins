<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Estimation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attachable_type' => Estimation::class,
            'attachable_id' => Estimation::factory(),
            'disk' => 'local',
            'path' => 'estimations/'.fake()->uuid().'/attachments/'.fake()->uuid().'.pdf',
            'original_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1000, 500000),
        ];
    }
}
