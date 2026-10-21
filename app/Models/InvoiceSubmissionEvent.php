<?php

namespace App\Models;

use Database\Factories\InvoiceSubmissionEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $submission_id
 * @property string $type
 * @property string $provider_event_id
 * @property array<string, mixed> $payload
 * @property Carbon $received_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['submission_id', 'type', 'provider_event_id', 'payload', 'received_at'])]
class InvoiceSubmissionEvent extends Model
{
    /** @use HasFactory<InvoiceSubmissionEventFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InvoiceSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(InvoiceSubmission::class, 'submission_id');
    }
}
