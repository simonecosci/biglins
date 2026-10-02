<?php

namespace App\Http\Requests;

use App\EInvoicing\CountryComplianceResolver;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
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
            ...$this->fiscalDetailsRules(),
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function fiscalDetailsRules(): array
    {
        $company = CurrentCompany::resolve();
        $rules = ($company ? CountryComplianceResolver::forCompany($company) : CountryComplianceResolver::forIsoCode(null))->customerFiscalRules();

        return [
            // `array:` with an empty key list is invalid, so countries without rules accept only an empty object.
            'fiscal_details' => $rules === []
                ? ['nullable', 'array', 'max:0']
                : ['nullable', 'array:'.implode(',', array_keys($rules))],
            ...collect($rules)->mapWithKeys(fn (array $fieldRules, string $key): array => ["fiscal_details.{$key}" => $fieldRules])->all(),
        ];
    }

    /**
     * Blank fiscal detail inputs are submitted as empty strings: drop them.
     */
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('fiscal_details'))) {
            $this->merge([
                'fiscal_details' => array_filter($this->input('fiscal_details'), fn ($value) => $value !== null && $value !== '') ?: null,
            ]);
        }
    }
}
