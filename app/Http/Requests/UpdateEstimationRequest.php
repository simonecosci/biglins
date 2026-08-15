<?php

namespace App\Http\Requests;

use App\Enums\EstimationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEstimationRequest extends FormRequest
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
            'estimation_date' => ['required', 'date'],
            'expiration_date' => ['required', 'date', 'after_or_equal:estimation_date'],
            'language' => ['required', 'string', Rule::in(['it', 'en', 'es'])],
            'status' => ['required', Rule::enum(EstimationStatus::class)],
            'body' => ['nullable', 'string'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.id' => ['nullable', 'uuid', 'exists:estimation_rows,id'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'rows.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
