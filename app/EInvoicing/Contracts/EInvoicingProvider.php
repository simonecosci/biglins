<?php

namespace App\EInvoicing\Contracts;

use App\EInvoicing\Data\ProviderNotification;
use App\EInvoicing\Data\SubmissionResult;
use App\EInvoicing\Enums\Capability;
use App\EInvoicing\Exceptions\InvalidWebhookSignature;
use App\EInvoicing\Exceptions\TransientProviderException;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Http\Request;

interface EInvoicingProvider
{
    /**
     * @throws TransientProviderException on timeouts, 5xx and 429
     */
    public function send(Invoice $invoice): SubmissionResult;

    /**
     * @throws TransientProviderException
     */
    public function fetchStatus(InvoiceSubmission $submission): SubmissionResult;

    /**
     * Verify the signature and normalise the notification; null when not relevant.
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(Request $request, EInvoicingIntegration $integration): ?ProviderNotification;

    public function testConnection(): bool;

    /**
     * @return list<Capability>
     */
    public function capabilities(): array;
}
