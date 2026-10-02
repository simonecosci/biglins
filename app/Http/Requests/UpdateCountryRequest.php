<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'iso_code' => $this->filled('iso_code') ? strtoupper($this->string('iso_code')->toString()) : null,
        ]);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('countries', 'name')->ignore($this->route('country')),
            ],
            'iso_code' => ['nullable', 'string', 'size:2', 'alpha', Rule::unique('countries', 'iso_code')->ignore($this->route('country'))],
        ];
    }
}
