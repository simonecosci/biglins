<?php

namespace App\Http\Requests;

use App\EInvoicing\CountryComplianceResolver;
use App\Http\Requests\Concerns\ValidatesFiscalDetails;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    use ValidatesFiscalDetails;

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
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country_id' => ['nullable', 'uuid', 'exists:countries,id'],
            'state' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'web' => ['nullable', 'url', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'tax_code' => ['nullable', 'string', 'max:50'],
            ...$this->fiscalDetailsRules($this->customerFiscalRules()),
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function customerFiscalRules(): array
    {
        $company = CurrentCompany::resolve();

        return ($company ? CountryComplianceResolver::forCompany($company) : CountryComplianceResolver::forIsoCode(null))->customerFiscalRules();
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->withoutBlankFiscalDetails());
    }
}
