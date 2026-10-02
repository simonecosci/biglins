<?php

namespace App\Http\Requests\Concerns;

trait ValidatesFiscalDetails
{
    /**
     * @param  array<string, array<mixed>>  $fiscalRules  Per-key rules, without the `fiscal_details.` prefix.
     * @return array<string, array<mixed>>
     */
    protected function fiscalDetailsRules(array $fiscalRules): array
    {
        return [
            // `array:` with an empty key list is invalid, so countries without rules accept only an empty object.
            'fiscal_details' => $fiscalRules === []
                ? ['nullable', 'array', 'max:0']
                : ['nullable', 'array:'.implode(',', array_keys($fiscalRules))],
            ...collect($fiscalRules)->mapWithKeys(fn (array $rules, string $key): array => ["fiscal_details.{$key}" => $rules])->all(),
        ];
    }

    /**
     * Blank inputs are submitted as empty strings: drop them, and null an empty result.
     *
     * @return array<string, array<string, mixed>|null>
     */
    protected function withoutBlankFiscalDetails(): array
    {
        $fiscalDetails = $this->input('fiscal_details');

        if (! is_array($fiscalDetails)) {
            return [];
        }

        return [
            'fiscal_details' => array_filter($fiscalDetails, fn ($value) => $value !== null && $value !== '') ?: null,
        ];
    }
}
