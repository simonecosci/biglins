<?php

namespace App\EInvoicing;

use App\EInvoicing\Compliance\DefaultComplianceRules;
use App\EInvoicing\Compliance\ItalyComplianceRules;
use App\EInvoicing\Compliance\SpainComplianceRules;
use App\EInvoicing\Contracts\CountryComplianceRules;
use App\Models\Company;
use App\Models\Country;

class CountryComplianceResolver
{
    public static function forCompany(Company $company): CountryComplianceRules
    {
        return self::forIsoCode($company->country?->iso_code);
    }

    public static function forCountryId(?string $countryId): CountryComplianceRules
    {
        return self::forIsoCode($countryId ? Country::query()->whereKey($countryId)->value('iso_code') : null);
    }

    public static function forIsoCode(?string $isoCode): CountryComplianceRules
    {
        return match ($isoCode) {
            'IT' => new ItalyComplianceRules,
            'ES' => new SpainComplianceRules,
            default => new DefaultComplianceRules,
        };
    }
}
