<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
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
            'tax_id' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country_id' => ['nullable', 'uuid', 'exists:countries,id'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'iban' => ['nullable', 'string', 'max:50'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'is_default' => ['boolean'],
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

        foreach (['tax_id', 'address', 'zip', 'city', 'country_id', 'email', 'phone', 'iban'] as $field) {
            if ($this->has($field)) {
                $normalized[$field] = $this->input($field) ?: null;
            }
        }

        $this->merge($normalized);
    }
}
