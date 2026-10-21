<?php

namespace App\Models;

use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use Database\Factories\EInvoicingIntegrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $company_id
 * @property EInvoicingDriver $driver
 * @property EInvoicingEnvironment $environment
 * @property array<string, string>|null $credentials
 * @property string $webhook_secret
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['company_id', 'driver', 'environment', 'credentials', 'is_active'])]
#[Hidden(['credentials', 'webhook_secret'])]
class EInvoicingIntegration extends Model
{
    /** @use HasFactory<EInvoicingIntegrationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => EInvoicingDriver::class,
            'environment' => EInvoicingEnvironment::class,
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EInvoicingIntegration $integration): void {
            $integration->webhook_secret ??= Str::random(40);
        });
    }

    public function supportsCountry(?string $isoCode): bool
    {
        return $this->driver->isAvailable() && in_array($isoCode, $this->driver->supportedCountries(), true);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<InvoiceSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(InvoiceSubmission::class);
    }
}
