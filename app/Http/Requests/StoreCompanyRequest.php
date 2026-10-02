<?php

namespace App\Http\Requests;

use App\EInvoicing\CountryComplianceResolver;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
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
            'vat_number' => ['nullable', 'string', 'max:50'],
            'tax_code' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:10'],
            'country_id' => ['nullable', 'uuid', 'exists:countries,id'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'iban' => ['nullable', 'string', 'max:50'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'is_default' => ['boolean'],
            ...$this->fiscalDetailsRules(),
        ];
    }

    /**
     * The frontend form always submits every optional field, sending an empty
     * string when the user left it blank. Normalise those to `null` so they are
     * persisted as `NULL` and never reach the `country_id` foreign key as `''`.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['vat_number', 'tax_code', 'address', 'zip', 'city', 'province', 'country_id', 'email', 'phone', 'iban'] as $field) {
            if ($this->has($field)) {
                $normalized[$field] = $this->input($field) ?: null;
            }
        }

        if (is_array($this->input('fiscal_details'))) {
            $normalized['fiscal_details'] = array_filter($this->input('fiscal_details'), fn ($value) => $value !== null && $value !== '') ?: null;
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function fiscalDetailsRules(): array
    {
        $fiscalRules = CountryComplianceResolver::forCountryId($this->input('country_id'))->companyFiscalRules();

        return [
            // `array:` with an empty key list is invalid, so countries without rules accept only an empty object.
            'fiscal_details' => $fiscalRules === []
                ? ['nullable', 'array', 'max:0']
                : ['nullable', 'array:'.implode(',', array_keys($fiscalRules))],
            ...collect($fiscalRules)->mapWithKeys(fn (array $rules, string $key): array => ["fiscal_details.{$key}" => $rules])->all(),
        ];
    }
}
