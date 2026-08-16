<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Country;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $company = DB::transaction(function () use ($request) {
            $isDefault = Company::query()->doesntExist() || $request->boolean('is_default');

            $company = Company::query()->create([
                ...$request->safe()->except(['is_default', 'logo', 'remove_logo']),
                'is_default' => $isDefault,
            ]);

            if ($isDefault) {
                Company::query()->whereKeyNot($company->id)->update(['is_default' => false]);
            }

            return $company;
        });

        $this->syncLogo($company, $request);

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
            $isDefault = $request->has('is_default') ? $request->boolean('is_default') : $company->is_default;

            $company->update([
                ...$request->safe()->except(['is_default', 'logo', 'remove_logo']),
                'is_default' => $isDefault,
            ]);

            if ($isDefault) {
                Company::query()->whereKeyNot($company->id)->update(['is_default' => false]);
            }
        });

        $this->syncLogo($company, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company updated.')]);

        return to_route('companies.index');
    }

    public function destroy(Company $company): RedirectResponse
    {
        if ($company->invoices()->exists() || $company->products()->exists() || $company->estimations()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This company has invoices, estimates or products and cannot be deleted.')]);

            return to_route('companies.index');
        }

        if ($company->logo) {
            $this->deleteLogoFile($company->logo);
        }

        $company->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company deleted.')]);

        return to_route('companies.index');
    }

    public function logo(Company $company): StreamedResponse
    {
        abort_unless($company->logo !== null && Storage::disk('local')->exists($company->logo), 404);

        return Storage::disk('local')->response($company->logo);
    }

    private function syncLogo(Company $company, StoreCompanyRequest|UpdateCompanyRequest $request): void
    {
        if ($request->hasFile('logo')) {
            if ($company->logo) {
                $this->deleteLogoFile($company->logo);
            }

            $file = $request->file('logo');
            $path = $file->storeAs('companies', $company->id.'.'.$file->extension(), 'local');

            if ($path === false) {
                return;
            }

            $company->update(['logo' => $path]);

            return;
        }

        if ($request->boolean('remove_logo') && $company->logo) {
            $this->deleteLogoFile($company->logo);
            $company->update(['logo' => null]);
        }
    }

    private function deleteLogoFile(string $path): void
    {
        Storage::disk('local')->delete($path);
    }
}
