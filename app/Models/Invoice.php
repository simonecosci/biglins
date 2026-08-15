<?php

namespace App\Models;

use App\Enums\InvoiceType;
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
 * @property InvoiceType $type
 * @property Carbon $invoice_date
 * @property bool $paid
 * @property string $customer_id
 * @property string $company_id
 * @property string|null $note
 * @property string $language
 * @property-read float $subtotal
 * @property-read float $vat_total
 * @property-read float $total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['number', 'type', 'invoice_date', 'paid', 'customer_id', 'company_id', 'note', 'language'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
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
            'type' => InvoiceType::class,
            'invoice_date' => 'date:Y-m-d',
            'paid' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice): void {
            if (! $invoice->number) {
                $invoice->number = static::nextNumber($invoice->company_id);
            }

            if (! $invoice->type) {
                $invoice->type = InvoiceType::Invoice;
            }
        });
    }

    public function isCreditNote(): bool
    {
        return $this->type === InvoiceType::CreditNote;
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
     * @return HasMany<InvoiceRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(InvoiceRow::class);
    }

    /**
     * @return Attribute<float, never>
     */
    protected function subtotal(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->rows->sum(
                fn (InvoiceRow $row): float => (float) $row->price * (float) $row->quantity
            ),
        );
    }

    /**
     * @return Attribute<float, never>
     */
    protected function vatTotal(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) $this->rows->sum(function (InvoiceRow $row): float {
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
