<?php

namespace App\Http\Requests;

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
            'fiscal_details' => ['nullable', 'array'],
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

        $this->merge($normalized);
    }
}
