<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $number
 * @property Carbon $invoice_date
 * @property bool $paid
 * @property string $customer_id
 * @property string|null $note
 * @property-read float $subtotal
 * @property-read float $vat_total
 * @property-read float $total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['number', 'invoice_date', 'paid', 'customer_id', 'note'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUuids;

    /**
     * @var array<int, string>
     */
    protected $appends = ['subtotal', 'vat_total', 'total'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date:Y-m-d',
            'paid' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice): void {
            if (! $invoice->number) {
                $invoice->number = static::nextNumber();
            }
        });
    }

    public static function nextNumber(?string $year = null): string
    {
        $year ??= now()->format('Y');

        $lastNumber = static::query()
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
     * @return HasMany<InvoiceRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(InvoiceRow::class);
    }

    protected function subtotal(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->rows->sum(fn (InvoiceRow $row): float => (float) $row->price),
        );
    }

    protected function vatTotal(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->rows->sum(
                fn (InvoiceRow $row): float => (float) $row->price * (float) $row->vat_rate / 100
            ),
        );
    }

    protected function total(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->subtotal + $this->vat_total,
        );
    }
}
