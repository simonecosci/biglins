<?php

namespace App\Models;

use Database\Factories\EstimationRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $estimation_id
 * @property string $description
 * @property float $quantity
 * @property float $price
 * @property float $vat_rate
 * @property string|null $note
 * @property-read float $total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['estimation_id', 'description', 'quantity', 'price', 'vat_rate', 'note'])]
class EstimationRow extends Model
{
    /** @use HasFactory<EstimationRowFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'price' => 'float',
            'vat_rate' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Estimation, $this>
     */
    public function estimation(): BelongsTo
    {
        return $this->belongsTo(Estimation::class);
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
}
