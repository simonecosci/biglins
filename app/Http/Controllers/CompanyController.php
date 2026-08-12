<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Country;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        $companies = Company::query()
            ->with('country')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('companies/Index', [
            'companies' => $companies,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('companies/Create', [
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $isDefault = Company::query()->doesntExist() || $request->boolean('is_default');

            $company = Company::query()->create([
                ...$request->safe()->except(['is_default', 'logo', 'remove_logo']),
                'is_default' => $isDefault,
            ]);

            if ($isDefault) {
                Company::query()->whereKeyNot($company->id)->update(['is_default' => false]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company created.')]);

        return to_route('companies.index');
    }

    public function edit(Company $company): Response
    {
        return Inertia::render('companies/Edit', [
            'company' => $company,
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        DB::transaction(function () use ($request, $company) {
            $isDefault = $request->boolean('is_default');

            $company->update([
                ...$request->safe()->except(['is_default', 'logo', 'remove_logo']),
                'is_default' => $isDefault,
            ]);

            if ($isDefault) {
                Company::query()->whereKeyNot($company->id)->update(['is_default' => false]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company updated.')]);

        return to_route('companies.index');
    }

    public function destroy(Company $company): RedirectResponse
    {
        if ($company->invoices()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This company has invoices and cannot be deleted.')]);

            return to_route('companies.index');
        }

        $company->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company deleted.')]);

        return to_route('companies.index');
    }
}
