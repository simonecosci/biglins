<?php

namespace App\Http\Requests;

use App\Support\CurrentCompany;
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
            'number' => [
                'nullable', 'string', 'max:20',
                Rule::unique('invoices', 'number')->where('company_id', CurrentCompany::resolve()?->id),
            ],
            'type' => ['sometimes', 'string', Rule::in(['invoice', 'credit_note'])],
            'invoice_date' => ['required', 'date'],
            'paid' => ['boolean'],
            'customer_id' => [
                'required', 'uuid',
                Rule::exists('customers', 'id')->where('company_id', CurrentCompany::resolve()?->id),
            ],
            'note' => ['nullable', 'string'],
            'language' => ['required', 'string', Rule::in(['it', 'en', 'es'])],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'rows.*.expiration_date' => ['nullable', 'date'],
        ];
    }
}
