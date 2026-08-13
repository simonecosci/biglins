<?php

namespace App\Models;

use App\Enums\ExpirationUrgency;
use App\Enums\SubscriptionStatus;
use Database\Factories\InvoiceRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $invoice_id
 * @property string $description
 * @property float $quantity
 * @property float $price
 * @property float $vat_rate
 * @property Carbon|null $expiration_date
 * @property SubscriptionStatus $subscription_status
 * @property-read float $total
 * @property-read ExpirationUrgency|null $expiration_urgency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['invoice_id', 'description', 'quantity', 'price', 'vat_rate', 'expiration_date', 'subscription_status'])]
class InvoiceRow extends Model
{
    /** @use HasFactory<InvoiceRowFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'price' => 'float',
            'vat_rate' => 'float',
            'expiration_date' => 'date',
            'subscription_status' => SubscriptionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopeSubscriptions(Builder $query): Builder
    {
        return $query
            ->whereNotNull('expiration_date')
            ->where('subscription_status', SubscriptionStatus::Active);
    }

    /**
     * @return Attribute<float, never>
     */
    protected function total(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                $lineTotal = (float) $this->price * (float) $this->quantity;

                return $lineTotal + $lineTotal * (float) $this->vat_rate / 100;
            },
        );
    }

    /**
     * @return Attribute<ExpirationUrgency|null, never>
     */
    protected function expirationUrgency(): Attribute
    {
        return Attribute::make(
            get: function (): ?ExpirationUrgency {
                if ($this->expiration_date === null) {
                    return null;
                }

                $today = Carbon::today();

                return match (true) {
                    $this->expiration_date->lt($today) => ExpirationUrgency::Expired,
                    $this->expiration_date->lte($today->copy()->addDays(30)) => ExpirationUrgency::ExpiringSoon,
                    default => ExpirationUrgency::Upcoming,
                };
            },
        );
    }
}
