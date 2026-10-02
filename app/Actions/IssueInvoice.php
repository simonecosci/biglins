<?php

namespace App\Actions;

use App\EInvoicing\Contracts\CountryComplianceRules;
use App\EInvoicing\CountryComplianceResolver;
use App\EInvoicing\Enums\SubmissionStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\SubmitInvoice;
use App\Models\Company;
use App\Models\EInvoicingIntegration;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class IssueInvoice
{
    public function handle(Invoice $invoice): Invoice
    {
        $invoice->load(['company.country', 'customer.country', 'rows']);

        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages(['invoice' => __('Only draft invoices can be issued.')]);
        }

        $rules = CountryComplianceResolver::forCompany($invoice->company);

        $errors = $rules->validateForIssue($invoice);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $integration = $this->ensureSubmissionIsPossible($invoice->company, $rules);

        [$issued, $submission] = DB::transaction(function () use ($invoice, $rules, $integration): array {
            Company::query()->whereKey($invoice->company_id)->lockForUpdate()->first();

            $locked = Invoice::query()
                ->with(['company.country', 'customer.country', 'rows'])
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== InvoiceStatus::Draft) {
                throw ValidationException::withMessages(['invoice' => __('Only draft invoices can be issued.')]);
            }

            $errors = $rules->validateForIssue($locked);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $alreadySubmitting = $locked->submissions()
                ->whereIn('status', [SubmissionStatus::Pending, SubmissionStatus::Submitted])
                ->exists();

            if ($alreadySubmitting) {
                throw ValidationException::withMessages(['invoice' => __('This invoice is already being submitted.')]);
            }

            $locked->number ??= Invoice::nextNumber($locked->company_id);
            $locked->status = InvoiceStatus::Issued;
            $locked->issued_at = now();
            $locked->save();

            $submission = $integration === null ? null : $locked->submissions()->create([
                'e_invoicing_integration_id' => $integration->id,
                'driver' => $integration->driver,
                'status' => SubmissionStatus::Pending,
            ]);

            return [$locked, $submission];
        });

        if ($submission !== null) {
            $this->submit($submission);
        }

        return $issued->refresh();
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

    protected function ensureSubmissionIsPossible(Company $company, CountryComplianceRules $rules): ?EInvoicingIntegration
    {
        if (! $rules->requiresSubmission()) {
            return null;
        }

        $integration = $company->eInvoicingIntegration;

        if ($integration === null || ! $integration->is_active || ! $integration->supportsCountry($company->country?->iso_code)) {
            throw ValidationException::withMessages([
                'einvoicing' => __('Configure electronic invoicing for this company before issuing invoices.'),
            ]);
        }

        return $integration;
    }
}
