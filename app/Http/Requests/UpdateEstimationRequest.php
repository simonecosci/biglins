<?php

namespace App\Http\Requests;

use App\EInvoicing\CountryComplianceResolver;
use App\Enums\EstimationStatus;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

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
            'rows.*.vat_exemption_code' => ['nullable', 'string', 'max:10', ...$this->vatExemptionCodeRule()],
            'rows.*.note' => ['nullable', 'string', 'max:255'],
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
