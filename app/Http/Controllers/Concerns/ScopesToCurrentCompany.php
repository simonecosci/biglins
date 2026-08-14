<?php

namespace App\Http\Controllers\Concerns;

use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

trait ScopesToCurrentCompany
{
    private function authorizeCurrentCompany(Model $record): void
    {
        abort_unless($record->getAttribute('company_id') === CurrentCompany::resolve()?->id, 403);
    }

    private function redirectToCreateCompany(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('Create a company before you can manage invoices, products, or customers.')]);

        return to_route('companies.create');
    }
}
