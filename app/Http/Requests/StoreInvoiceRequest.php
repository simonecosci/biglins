<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'number' => ['nullable', 'string', 'max:20', 'unique:invoices,number'],
            'invoice_date' => ['required', 'date'],
            'paid' => ['boolean'],
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'note' => ['nullable', 'string'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
