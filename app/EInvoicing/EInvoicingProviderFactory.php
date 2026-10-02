<?php

namespace App\EInvoicing;

use App\EInvoicing\Contracts\EInvoicingProvider;
use App\Models\EInvoicingIntegration;
use InvalidArgumentException;

class EInvoicingProviderFactory
{
    public function forIntegration(EInvoicingIntegration $integration): EInvoicingProvider
    {
        if (! $integration->driver->isAvailable()) {
            throw new InvalidArgumentException("The [{$integration->driver->value}] e-invoicing driver is not available.");
        }

        return app()->makeWith($integration->driver->providerClass(), [
            'credentials' => $integration->credentials ?? [],
            'environment' => $integration->environment,
        ]);
    }
}
