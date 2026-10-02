<?php

namespace App\Http\Requests\Concerns;

use App\EInvoicing\CountryComplianceResolver;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

trait ValidatesVatExemptionCodes
{
    /**
     * @return list<In>
     */
    protected function vatExemptionCodeRule(): array
    {
        $company = CurrentCompany::resolve();
        $codes = $company ? CountryComplianceResolver::forCompany($company)->vatExemptionCodes() : [];

        return $codes === [] ? [] : [Rule::in($codes)];
    }
}
