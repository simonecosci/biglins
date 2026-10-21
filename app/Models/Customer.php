<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string|null $address
 * @property string|null $zip
 * @property string|null $city
 * @property string|null $country_id
 * @property string|null $state
 * @property string|null $email
 * @property string|null $web
 * @property string|null $phone
 * @property string|null $vat_number
 * @property string|null $tax_code
 * @property array<string, mixed>|null $fiscal_details
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['company_id', 'name', 'address', 'zip', 'city', 'country_id', 'state', 'email', 'web', 'phone', 'vat_number', 'tax_code', 'fiscal_details'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiscal_details' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
