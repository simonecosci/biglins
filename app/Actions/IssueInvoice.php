<?php

namespace App\Actions;

use App\EInvoicing\Contracts\CountryComplianceRules;
use App\EInvoicing\CountryComplianceResolver;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        $this->ensureSubmissionIsPossible($invoice->company, $rules);

        return DB::transaction(function () use ($invoice, $rules): Invoice {
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

            $locked->number ??= Invoice::nextNumber($locked->company_id);
            $locked->status = InvoiceStatus::Issued;
            $locked->issued_at = now();
            $locked->save();

            return $locked;
        });
    }

    protected function ensureSubmissionIsPossible(Company $company, CountryComplianceRules $rules): void
    {
        if ($rules->requiresSubmission()) {
            throw ValidationException::withMessages([
                'einvoicing' => __('Configure electronic invoicing for this company before issuing invoices.'),
            ]);
        }
    }
}
