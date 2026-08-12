<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'note' => ['nullable', 'string'],
            'language' => ['required', 'string', Rule::in(['it', 'en', 'es'])],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
