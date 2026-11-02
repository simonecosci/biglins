<?php

namespace App\Actions;

use App\EInvoicing\CountryComplianceResolver;
use App\EInvoicing\Enums\SubmissionStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\SubmitInvoice;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sends an issued invoice to the tax authority (SDI, VERI*FACTU) through the company integration.
 */
class SubmitElectronicInvoice
{
    public function handle(Invoice $invoice): Invoice
    {
        $invoice->load(['company.country', 'company.eInvoicingIntegration', 'customer.country', 'rows']);

        $this->ensureCanBeSubmitted($invoice);

        $submission = DB::transaction(function () use ($invoice): InvoiceSubmission {
            $locked = Invoice::query()
                ->with(['company.country', 'company.eInvoicingIntegration', 'customer.country', 'rows'])
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $integration = $this->ensureCanBeSubmitted($locked);

            return $locked->submissions()->create([
                'e_invoicing_integration_id' => $integration->id,
                'driver' => $integration->driver,
                'status' => SubmissionStatus::Pending,
            ]);
        });

        $this->submit($submission);

        return $invoice->refresh();
    }

    protected function ensureCanBeSubmitted(Invoice $invoice): EInvoicingIntegration
    {
        $company = $invoice->company;

        if (! $company->usesElectronicInvoicing()) {
            throw ValidationException::withMessages([
                'einvoicing' => __('Electronic invoicing is not enabled for this company.'),
            ]);
        }

        if ($invoice->status !== InvoiceStatus::Issued) {
            throw ValidationException::withMessages(['invoice' => __('Issue the invoice before sending it electronically.')]);
        }

        if ($invoice->hasBeenSubmitted()) {
            throw ValidationException::withMessages(['invoice' => __('This invoice has already been sent electronically.')]);
        }

        $errors = CountryComplianceResolver::forCompany($company)->validateForSubmission($invoice);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $integration = $company->eInvoicingIntegration;

        if ($integration === null || ! $integration->is_active || ! $integration->supportsCountry($company->country?->iso_code)) {
            throw ValidationException::withMessages([
                'einvoicing' => __('Configure electronic invoicing for this company before sending invoices.'),
            ]);
        }

        return $integration;
    }

    /**
     * The desktop build has no queue workers, so the submission runs inline there.
     */
    protected function submit(InvoiceSubmission $submission): void
    {
        if (! config('nativephp-internal.running')) {
            SubmitInvoice::dispatch($submission)->afterCommit();

            return;
        }

        try {
            SubmitInvoice::dispatchSync($submission);
        } catch (Throwable $exception) {
            report($exception);

            (new SubmitInvoice($submission))->failed($exception);
        }
    }
}
