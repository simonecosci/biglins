<?php

namespace App\Support;

use App\Models\Company;
use SortDirection;

class CurrentCompany
{
    public static function resolve(): ?Company
    {
        $sessionId = session('current_company_id');

        if (is_string($sessionId)) {
            $company = Company::query()->find($sessionId);

            if ($company !== null) {
                return $company;
            }
        }

        return Company::query()->where('is_default', true)->first()
            ?? Company::query()->orderBy('name', SortDirection::Ascending)->first();
    }
}
