<?php

namespace App\Support;

use App\Models\Company;
use Closure;
use SortDirection;

class CurrentCompany
{
    private static ?Company $override = null;

    public static function resolve(): ?Company
    {
        if (self::$override !== null) {
            return self::$override;
        }

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

    public static function runningAs(Company $company, Closure $callback): mixed
    {
        $previous = self::$override;
        self::$override = $company;

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }
}
