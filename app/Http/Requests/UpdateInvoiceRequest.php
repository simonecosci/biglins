<?php

namespace App\Http\Requests;

use App\EInvoicing\CountryComplianceResolver;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class UpdateInvoiceRequest extends FormRequest
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
                Rule::unique('invoices', 'number')
                    ->where('company_id', CurrentCompany::resolve()?->id)
                    ->ignore($this->route('invoice')),
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
            'rows.*.id' => ['nullable', 'uuid', 'exists:invoice_rows,id'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'rows.*.vat_exemption_code' => ['nullable', 'string', 'max:10', ...$this->vatExemptionCodeRule()],
            'rows.*.expiration_date' => ['nullable', 'date'],
            'rows.*.subscription_status' => ['nullable', Rule::in(['active', 'cancelled'])],
        ];
    }

    /**
     * @return list<In>
     */
    private function vatExemptionCodeRule(): array
    {
        $company = CurrentCompany::resolve();
        $codes = $company ? CountryComplianceResolver::forCompany($company)->vatExemptionCodes() : [];

        return $codes === [] ? [] : [Rule::in($codes)];
    }
}
