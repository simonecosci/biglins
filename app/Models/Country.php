<?php

namespace App\Models;

use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string|null $iso_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'iso_code'])]
class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory, HasUuids;

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function isoCode(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null || $value === '' ? null : strtoupper($value),
        );
    }
}
