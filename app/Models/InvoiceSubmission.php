<?php

namespace App\Models;

use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Enums\SubmissionStatus;
use Database\Factories\InvoiceSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $invoice_id
 * @property string $e_invoicing_integration_id
 * @property EInvoicingDriver $driver
 * @property SubmissionStatus $status
 * @property string|null $provider_status
 * @property string|null $external_id
 * @property string|null $authority_id
 * @property string|null $qr_code
 * @property string|null $error_message
 * @property string|null $payload_path
 * @property Carbon|null $submitted_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['qr_code', 'payload_path'])]
#[Fillable(['invoice_id', 'e_invoicing_integration_id', 'driver', 'status', 'provider_status', 'external_id', 'authority_id', 'qr_code', 'error_message', 'payload_path', 'submitted_at', 'completed_at'])]
class InvoiceSubmission extends Model
{
    /** @use HasFactory<InvoiceSubmissionFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => EInvoicingDriver::class,
            'status' => SubmissionStatus::class,
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<EInvoicingIntegration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(EInvoicingIntegration::class, 'e_invoicing_integration_id');
    }

    /**
     * @return HasMany<InvoiceSubmissionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(InvoiceSubmissionEvent::class, 'submission_id')->latest('received_at');
    }
}
