<?php

namespace App\Models;

use App\Enums\EstimationStatus;
use Database\Factories\EstimationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $company_id
 * @property string $customer_id
 * @property string $number
 * @property Carbon $estimation_date
 * @property Carbon $expiration_date
 * @property string $language
 * @property string|null $body
 * @property EstimationStatus $status
 * @property string|null $invoice_id
 * @property Carbon|null $sent_at
 * @property string|null $sent_to
 * @property-read bool $is_expired
 * @property-read float $subtotal
 * @property-read float $vat_total
 * @property-read float $total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['company_id', 'customer_id', 'estimation_date', 'expiration_date', 'language', 'body', 'status', 'invoice_id'])]
class Estimation extends Model
{
    /** @use HasFactory<EstimationFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $appends = ['subtotal', 'vat_total', 'total'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimation_date' => 'date:Y-m-d',
            'expiration_date' => 'date:Y-m-d',
            'status' => EstimationStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Estimation $estimation): void {
            if (! $estimation->number) {
                $estimation->number = static::nextNumber($estimation->company_id);
            }
        });

        static::deleting(function (Estimation $estimation): void {
            foreach ($estimation->attachments as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }

            $estimation->attachments()->delete();
        });
    }

    public static function nextNumber(string $companyId, ?string $year = null): string
    {
        $year ??= now()->format('Y');

        $lastNumber = static::query()
            ->where('company_id', $companyId)
            ->where('number', 'like', "{$year}-%")
            ->orderByDesc('number')
            ->value('number');

        $sequence = $lastNumber
            ? ((int) substr($lastNumber, strlen($year) + 1)) + 1
            : 1;

        return sprintf('%s-%04d', $year, $sequence);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isExpired(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->status === EstimationStatus::Pending
                && $this->expiration_date->lt(Carbon::today()),
        );
    }

    /**
     * @return HasMany<EstimationRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(EstimationRow::class);
    }

    /**
     * @return Attribute<float, never>
     */
    protected function subtotal(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->rows->sum(
                fn (EstimationRow $row): float => (float) $row->price * (float) $row->quantity
            ),
        );
    }

    /**
     * @return Attribute<float, never>
     */
    protected function vatTotal(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->rows->sum(function (EstimationRow $row): float {
                $lineTotal = (float) $row->price * (float) $row->quantity;

                return $lineTotal * (float) $row->vat_rate / 100;
            }),
        );
    }

    /**
     * @return Attribute<float, never>
     */
    protected function total(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->subtotal + $this->vat_total,
        );
    }
}
