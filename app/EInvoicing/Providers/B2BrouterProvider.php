<?php

namespace App\EInvoicing\Providers;

use App\EInvoicing\Contracts\EInvoicingProvider;
use App\EInvoicing\Data\ProviderNotification;
use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\EInvoicingEnvironment;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Http\Request;
use LogicException;

/**
 * Placeholder until the real B2Brouter integration is implemented.
 */
class B2BrouterProvider implements EInvoicingProvider
{
    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(private array $credentials, private EInvoicingEnvironment $environment) {}

    public function send(Invoice $invoice): SubmissionResult
    {
        throw new LogicException('B2Brouter driver not implemented yet.');
    }

    public function fetchStatus(InvoiceSubmission $submission): SubmissionResult
    {
        throw new LogicException('B2Brouter driver not implemented yet.');
    }

    public function parseWebhook(Request $request, EInvoicingIntegration $integration): ?ProviderNotification
    {
        throw new LogicException('B2Brouter driver not implemented yet.');
    }

    public function testConnection(): bool
    {
        throw new LogicException('B2Brouter driver not implemented yet.');
    }

    public function capabilities(): array
    {
        return [];
    }
}
