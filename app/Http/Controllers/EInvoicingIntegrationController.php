<?php

namespace App\Http\Controllers;

use App\EInvoicing\EInvoicingProviderFactory;
use App\EInvoicing\Enums\EInvoicingDriver;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use App\Http\Requests\UpdateEInvoicingIntegrationRequest;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EInvoicingIntegrationController extends Controller
{
    public function edit(Company $company): Response
    {
        $integration = $company->eInvoicingIntegration;

        return Inertia::render('companies/EInvoicing', [
            'company' => ['id' => $company->id, 'name' => $company->name, 'country_iso' => $company->country?->iso_code],
            'drivers' => array_map(fn (EInvoicingDriver $driver): array => [
                'value' => $driver->value,
                'label' => $driver->label(),
                'fields' => $driver->credentialFields(),
            ], EInvoicingDriver::availableFor($company->country?->iso_code)),
            'environments' => array_column(EInvoicingEnvironment::cases(), 'value'),
            'integration' => $integration ? [
                'driver' => $integration->driver->value,
                'environment' => $integration->environment->value,
                'is_active' => $integration->is_active,
                'configured_credentials' => array_keys(array_filter($integration->credentials ?? [], fn (?string $value): bool => filled($value))),
            ] : null,
            'webhookUrl' => $integration && ! config('nativephp-internal.running')
                ? route('einvoicing.webhook', ['driver' => $integration->driver->value, 'integration' => $integration->id, 'secret' => $integration->webhook_secret])
                : null,
        ]);
    }

    public function update(UpdateEInvoicingIntegrationRequest $request, Company $company): RedirectResponse
    {
        $credentials = $request->mergedCredentials();

        $company->eInvoicingIntegration()->updateOrCreate([], [
            'driver' => $request->validated('driver'),
            'environment' => $request->validated('environment'),
            'is_active' => $request->boolean('is_active'),
            'credentials' => $credentials,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Electronic invoicing settings saved.')]);

        return to_route('companies.e-invoicing.edit', $company);
    }

    public function test(Company $company, EInvoicingProviderFactory $factory): RedirectResponse
    {
        $integration = $company->eInvoicingIntegration;

        $succeeded = $integration !== null
            && rescue(fn (): bool => $factory->forIntegration($integration)->testConnection(), false);

        Inertia::flash('toast', $succeeded
            ? ['type' => 'success', 'message' => __('Connection successful.')]
            : ['type' => 'error', 'message' => __('Connection failed: check the credentials and the environment.')]);

        return to_route('companies.e-invoicing.edit', $company);
    }
}
