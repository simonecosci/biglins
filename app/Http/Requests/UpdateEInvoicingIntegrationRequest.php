<?php

namespace App\Http\Requests;

use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEInvoicingIntegrationRequest extends FormRequest
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
        $availableDrivers = array_map(
            fn (EInvoicingDriver $driver): string => $driver->value,
            EInvoicingDriver::availableFor($this->company()->country?->iso_code),
        );

        return [
            'driver' => ['required', 'string', Rule::in($availableDrivers)],
            'environment' => ['required', Rule::enum(EInvoicingEnvironment::class)],
            'is_active' => ['boolean'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Credentials merged with the stored ones (a blank field keeps the stored value) and validated against the driver rules.
     *
     * @return array<string, string>
     */
    public function mergedCredentials(): array
    {
        $driver = EInvoicingDriver::from($this->validated('driver'));
        $existing = $this->company()->eInvoicingIntegration;
        $stored = $existing?->driver === $driver ? ($existing->credentials ?? []) : [];

        $input = array_filter($this->validated('credentials') ?? [], fn (?string $value): bool => filled($value));
        $merged = array_intersect_key([...$stored, ...$input], $driver->credentialRules());

        validator(
            ['credentials' => $merged],
            collect($driver->credentialRules())->mapWithKeys(fn (array $rules, string $key): array => ["credentials.{$key}" => $rules])->all(),
        )->validate();

        return $merged;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->route('company');

        return $company;
    }
}
